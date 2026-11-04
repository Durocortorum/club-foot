<?php

namespace App\Command;

use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Recopie l'ancienne application mono-club (base « stade_reims », sur le même serveur MySQL)
 * comme un nouveau club. Les identifiants sont décalés pour ne pas heurter ceux des clubs
 * existants ; les comptes reçoivent l'adresse « identifiant@stade-reims.invalid »
 * et gardent leur mot de passe.
 */
#[AsCommand(name: 'app:import:stade-reims', description: 'Importe l\'ancienne base du Stade de Reims comme un nouveau club')]
class ImportStadeReimsCommand extends Command
{
    private const DOMAINE_EMAIL = 'stade-reims.invalid';

    public function __construct(
        private readonly Connection $db,
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projet,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('base', null, InputOption::VALUE_REQUIRED, 'Base de l\'ancienne application', 'stade_reims')
            ->addOption('dossier', null, InputOption::VALUE_REQUIRED, 'Dossier de l\'ancienne application (photos, logo)', '%projet%/../stade-reims')
            ->addOption('essai', null, InputOption::VALUE_NONE, 'Tout importer puis annuler, pour vérifier');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $source = $input->getOption('base');
        $cible = $this->db->getDatabase();
        $dossier = str_replace('%projet%', $this->projet, $input->getOption('dossier'));
        $essai = $input->getOption('essai');

        $ancien = $this->db->fetchAssociative("SELECT * FROM `$source`.club LIMIT 1");
        if (!$ancien) {
            $io->error("Aucun club trouvé dans la base « $source ».");

            return Command::FAILURE;
        }
        if ($this->db->fetchOne('SELECT id FROM club WHERE nom = ?', [$ancien['nom']])) {
            $io->error("Le club « {$ancien['nom']} » existe déjà : import déjà fait ?");

            return Command::FAILURE;
        }

        $tables = $this->tables($source, $cible);
        $fichiers = [];

        $this->db->beginTransaction();
        try {
            $this->db->insert('club', array_intersect_key($ancien, array_flip(['nom', 'couleur', 'couleur_texte', 'logo', 'modifie_le'])));
            $club = (int) $this->db->lastInsertId();

            $decalages = [];
            foreach ($tables as $table => $cles) {
                $decalages[$table] = (int) $this->db->fetchOne("SELECT COALESCE(MAX(id), 0) FROM `$table`");
            }

            $lignes = [];
            foreach ($tables as $table => $cles) {
                $lignes[$table] = $this->copier($source, $cible, $table, $cles, $decalages, $club);
                $io->writeln(sprintf('  %-20s %6d', $table, $lignes[$table]));
            }

            $fichiers = $this->renommerPhotos($club, $dossier);

            $essai ? $this->db->rollBack() : $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }

        if ($essai) {
            $io->note(sprintf('Essai : %d lignes et %d fichiers importables, rien n\'a été enregistré.', array_sum($lignes), \count($fichiers)));

            return Command::SUCCESS;
        }

        $manquants = 0;
        foreach ($fichiers as $depuis => $vers) {
            if (!is_file($depuis)) {
                ++$manquants;
                continue;
            }
            if (!is_dir(\dirname($vers))) {
                mkdir(\dirname($vers), 0775, true);
            }
            copy($depuis, $vers);
        }

        $io->success(sprintf('« %s » importé : %d lignes, %d fichiers copiés%s.', $ancien['nom'], array_sum($lignes), \count($fichiers) - $manquants, $manquants ? ", $manquants introuvables" : ''));
        $io->text('Connexion : identifiant@' . self::DOMAINE_EMAIL . ' (par ex. michel-descotes@' . self::DOMAINE_EMAIL . ') avec le mot de passe de l\'ancienne application.');
        $io->text('Les adresses peuvent ensuite être corrigées dans « Utilisateurs ».');

        return Command::SUCCESS;
    }

    /**
     * Tables de données de club présentes dans les deux bases, chacune avec ses clés étrangères
     * (colonne => table visée), rangées pour qu'une table vienne après celles qu'elle vise.
     *
     * @return array<string, array<string, string>>
     */
    private function tables(string $source, string $cible): array
    {
        $noms = $this->db->fetchFirstColumn(
            "SELECT c.TABLE_NAME FROM information_schema.COLUMNS c
             JOIN information_schema.TABLES s ON s.TABLE_SCHEMA = ? AND s.TABLE_NAME = c.TABLE_NAME
             WHERE c.TABLE_SCHEMA = ? AND c.COLUMN_NAME = 'club_id' AND c.TABLE_NAME <> 'club'",
            [$source, $cible],
        );
        $cles = array_fill_keys($noms, []);
        foreach ($this->db->fetchAllAssociative(
            "SELECT TABLE_NAME, COLUMN_NAME, REFERENCED_TABLE_NAME FROM information_schema.KEY_COLUMN_USAGE
             WHERE TABLE_SCHEMA = ? AND REFERENCED_TABLE_NAME IS NOT NULL AND REFERENCED_TABLE_NAME <> 'club'",
            [$cible],
        ) as $cle) {
            if (isset($cles[$cle['TABLE_NAME']])) {
                $cles[$cle['TABLE_NAME']][$cle['COLUMN_NAME']] = $cle['REFERENCED_TABLE_NAME'];
            }
        }

        $ordre = [];
        while (\count($ordre) < \count($cles)) {
            $avant = \count($ordre);
            foreach ($cles as $table => $visees) {
                if (!isset($ordre[$table]) && !array_diff(array_unique($visees), [$table], array_keys($ordre))) {
                    $ordre[$table] = $visees;
                }
            }
            if (\count($ordre) === $avant) {
                throw new \RuntimeException('Dépendances circulaires entre ' . implode(', ', array_diff(array_keys($cles), array_keys($ordre))));
            }
        }

        return $ordre;
    }

    /**
     * @param array<string, string> $cles
     * @param array<string, int>    $decalages
     */
    private function copier(string $source, string $cible, string $table, array $cles, array $decalages, int $club): int
    {
        $colonnes = fn (string $base) => $this->db->fetchFirstColumn(
            'SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? ORDER BY ORDINAL_POSITION',
            [$base, $table],
        );
        $anciennes = $colonnes($source);
        $jointure = \in_array('ville_id', $anciennes, true) ? "LEFT JOIN `$source`.ville v ON v.id = s.ville_id" : '';

        $valeurs = [];
        foreach ($colonnes($cible) as $colonne) {
            $valeurs["`$colonne`"] = match (true) {
                'club_id' === $colonne => (string) $club,
                'id' === $colonne => "s.id + {$decalages[$table]}",
                isset($cles[$colonne]) => "s.`$colonne` + {$decalages[$cles[$colonne]]}",
                // L'ancienne application rangeait les villes dans une table à part.
                $jointure && 'code_postal' === $colonne => 'v.code_postal',
                $jointure && 'ville' === $colonne => 'v.nom',
                'joueur_observe' === $table && 'club_actuel' === $colonne => 's.club',
                'utilisateur' === $table && 'email' === $colonne => "CONCAT(s.identifiant, '@" . self::DOMAINE_EMAIL . "')",
                \in_array($colonne, $anciennes, true) => "s.`$colonne`",
                default => throw new \RuntimeException("Colonne $table.$colonne absente de l'ancienne base."),
            };
        }

        return (int) $this->db->executeStatement(sprintf(
            'INSERT INTO `%s` (%s) SELECT %s FROM `%s`.`%s` s %s',
            $table, implode(', ', array_keys($valeurs)), implode(', ', $valeurs), $source, $table, $jointure,
        ));
    }

    /**
     * Donne aux photos et documents importés un nom neuf, pour ne pas heurter les fichiers
     * des autres clubs, et renvoie les copies à faire (ancien chemin => nouveau chemin).
     *
     * @return array<string, string>
     */
    private function renommerPhotos(int $club, string $dossier): array
    {
        $copies = [];
        $tables = $this->db->fetchAllAssociative(
            "SELECT TABLE_NAME t, COLUMN_NAME c FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = ? AND COLUMN_NAME IN ('photo', 'fichier') AND TABLE_NAME <> 'club'",
            [$this->db->getDatabase()],
        );
        foreach ($tables as ['t' => $table, 'c' => $colonne]) {
            $sousDossier = 'photo' === $colonne ? 'photos' : 'documents';
            foreach ($this->db->fetchAllKeyValue("SELECT id, `$colonne` FROM `$table` WHERE club_id = ? AND `$colonne` IS NOT NULL", [$club]) as $id => $nom) {
                $nouveau = sprintf('%s-%s.%s', strtok($nom, '-'), bin2hex(random_bytes(4)), pathinfo($nom, \PATHINFO_EXTENSION));
                $this->db->update($table, [$colonne => $nouveau], ['id' => $id]);
                $copies["$dossier/var/$sousDossier/$nom"] = "{$this->projet}/var/$sousDossier/$nouveau";
            }
        }

        $logo = $this->db->fetchOne('SELECT logo FROM club WHERE id = ?', [$club]);
        if ($logo) {
            foreach ([$logo, "icone-$logo"] as $nom) {
                $copies["$dossier/var/club/$nom"] = "{$this->projet}/var/club/$nom";
            }
        }

        return $copies;
    }
}
