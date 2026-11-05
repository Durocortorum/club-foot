<?php

namespace App\Service;

use App\Entity\Categorie;
use App\Entity\Joueur;
use App\Repository\JoueurRepository;

/**
 * Import de joueurs depuis un fichier CSV (enregistré depuis Excel ou un tableur) :
 * lecture et contrôle ligne par ligne, puis création des fiches après confirmation.
 * Les colonnes sont reconnues par leur titre, dans n'importe quel ordre.
 */
class ImportJoueurs
{
    public const MAX_LIGNES = 1000;

    /** Titre de colonne (sans accents ni ponctuation) => champ de la fiche joueur. */
    private const COLONNES = [
        'nom' => 'nom', 'prenom' => 'prenom',
        'date de naissance' => 'dateNaissance', 'naissance' => 'dateNaissance', 'ne le' => 'dateNaissance', 'nee le' => 'dateNaissance',
        'lieu de naissance' => 'lieuNaissance', 'nationalite' => 'nationalite',
        'categorie' => 'categorie', 'equipe' => 'categorie',
        'poste' => 'poste', 'pied' => 'pied', 'pied fort' => 'pied',
        'licence' => 'licence', 'n licence' => 'licence', 'numero de licence' => 'licence', 'no licence' => 'licence',
        'adresse' => 'adresse', 'code postal' => 'codePostal', 'cp' => 'codePostal', 'ville' => 'ville',
        'portable' => 'telJoueur', 'portable du joueur' => 'telJoueur', 'telephone' => 'telJoueur', 'tel' => 'telJoueur', 'tel joueur' => 'telJoueur',
        'email' => 'emailJoueur', 'e mail' => 'emailJoueur', 'email du joueur' => 'emailJoueur', 'e mail du joueur' => 'emailJoueur',
        // Un ancien fichier avec une seule colonne « E-mail des parents » la range dans le parent 1.
        'email des parents' => 'emailParent1', 'e mail des parents' => 'emailParent1', 'email parents' => 'emailParent1',
        'email parent 1' => 'emailParent1', 'e mail parent 1' => 'emailParent1', 'email parent 2' => 'emailParent2', 'e mail parent 2' => 'emailParent2',
        'portable parent 1' => 'telPortable1', 'tel parent 1' => 'telPortable1', 'portable parent 2' => 'telPortable2', 'tel parent 2' => 'telPortable2',
        'date d arrivee' => 'dateArrivee', 'arrivee' => 'dateArrivee',
    ];

    /** Colonnes du fichier modèle proposé au téléchargement. */
    public const MODELE = ['Nom', 'Prénom', 'Date de naissance', 'Catégorie', 'Poste', 'Pied fort', 'N° licence', 'Adresse', 'Code postal', 'Ville',
        'Portable du joueur', 'E-mail du joueur', 'Portable parent 1', 'E-mail parent 1', 'Portable parent 2', 'E-mail parent 2'];

    public function __construct(private readonly JoueurRepository $joueurs)
    {
    }

    /**
     * Lit le fichier et contrôle chaque ligne.
     *
     * @param array<string, Categorie> $categories catégories autorisées, par nom
     *
     * @return array{colonnes: list<string>, ignorees: list<string>, lignes: list<array{numero: int, valeurs: array<string, string>, erreurs: list<string>, avertissements: list<string>, doublon: bool}>, erreur: string|null}
     */
    public function analyser(string $contenu, array $categories, ?Categorie $parDefaut): array
    {
        $resultat = ['colonnes' => [], 'ignorees' => [], 'lignes' => [], 'erreur' => null];
        if (!mb_check_encoding($contenu, 'UTF-8')) {
            // Excel enregistre souvent les CSV en Windows-1252.
            $contenu = mb_convert_encoding($contenu, 'UTF-8', 'Windows-1252');
        }
        $contenu = preg_replace('/^\xEF\xBB\xBF/', '', $contenu);
        $lignes = preg_split('/\r\n|\r|\n/', trim($contenu));
        $entete = $lignes[0] ?? '';
        $separateur = array_search(max($comptes = [';' => substr_count($entete, ';'), ',' => substr_count($entete, ','), "\t" => substr_count($entete, "\t")]), $comptes, true);

        $champs = [];
        foreach (str_getcsv($entete, $separateur, '"', '') as $i => $titre) {
            $cle = self::normaliser($titre);
            if (isset(self::COLONNES[$cle])) {
                $champs[$i] = self::COLONNES[$cle];
                $resultat['colonnes'][] = trim($titre);
            } elseif ('' !== $cle) {
                $resultat['ignorees'][] = trim($titre);
            }
        }
        if (!\in_array('nom', $champs, true) || !\in_array('prenom', $champs, true)) {
            $resultat['erreur'] = 'Le fichier doit avoir une première ligne de titres, avec au moins les colonnes « Nom » et « Prénom ».';

            return $resultat;
        }
        if (\count($lignes) - 1 > self::MAX_LIGNES) {
            $resultat['erreur'] = \sprintf('Le fichier contient plus de %d joueurs : découpez-le en plusieurs fichiers.', self::MAX_LIGNES);

            return $resultat;
        }

        $existants = [];
        foreach ($this->joueurs->findAll() as $j) {
            $existants[self::cle($j->getNom(), $j->getPrenom(), $j->getDateNaissance()?->format('Y-m-d'))] = true;
        }
        $categoriesNormalisees = [];
        foreach ($categories as $nom => $c) {
            $categoriesNormalisees[self::normaliser($nom)] = $c;
        }

        foreach (\array_slice($lignes, 1) as $n => $texte) {
            if ('' === trim(str_replace($separateur, '', $texte))) {
                continue;
            }
            $cellules = str_getcsv($texte, $separateur, '"', '');
            $valeurs = [];
            foreach ($champs as $i => $champ) {
                $valeurs[$champ] = trim((string) ($cellules[$i] ?? ''));
            }
            $ligne = ['numero' => $n + 2, 'valeurs' => [], 'erreurs' => [], 'avertissements' => [], 'doublon' => false];
            $this->controler($valeurs, $ligne, $categoriesNormalisees, $parDefaut);
            $cle = self::cle($ligne['valeurs']['nom'] ?? '', $ligne['valeurs']['prenom'] ?? '', $ligne['valeurs']['dateNaissance'] ?? null);
            if (!$ligne['erreurs'] && isset($existants[$cle])) {
                $ligne['doublon'] = true;
            }
            $existants[$cle] = true;
            $resultat['lignes'][] = $ligne;
        }

        return $resultat;
    }

    /**
     * Crée les fiches des lignes valides (non doublons).
     *
     * @param list<array{valeurs: array<string, string>, erreurs: list<string>, doublon: bool}> $lignes
     * @param array<int, Categorie>                                                          $categories par id
     *
     * @return list<Joueur>
     */
    public function creer(array $lignes, array $categories): array
    {
        $crees = [];
        foreach ($lignes as $ligne) {
            if ($ligne['erreurs'] || $ligne['doublon']) {
                continue;
            }
            $v = $ligne['valeurs'];
            $joueur = (new Joueur())->setNom($v['nom'])->setPrenom($v['prenom'])->setCategorie($categories[(int) $v['categorie']] ?? null);
            foreach (['dateNaissance', 'dateArrivee'] as $date) {
                if (!empty($v[$date])) {
                    $joueur->{'set' . ucfirst($date)}(new \DateTimeImmutable($v[$date]));
                }
            }
            foreach (['lieuNaissance', 'nationalite', 'poste', 'pied', 'licence', 'adresse', 'codePostal', 'ville', 'telJoueur', 'emailJoueur', 'emailParent1', 'emailParent2', 'telPortable1', 'telPortable2'] as $champ) {
                if (isset($v[$champ]) && '' !== $v[$champ]) {
                    $joueur->{'set' . ucfirst($champ)}($v[$champ]);
                }
            }
            $crees[] = $joueur;
        }

        return $crees;
    }

    /**
     * @param array<string, string>    $valeurs
     * @param array<string, Categorie> $categories par nom normalisé
     */
    private function controler(array $valeurs, array &$ligne, array $categories, ?Categorie $parDefaut): void
    {
        $v = &$ligne['valeurs'];
        $v['nom'] = mb_strtoupper(mb_substr($valeurs['nom'] ?? '', 0, 50));
        $v['prenom'] = mb_convert_case(mb_substr($valeurs['prenom'] ?? '', 0, 50), \MB_CASE_TITLE);
        if ('' === $v['nom'] || '' === $v['prenom']) {
            $ligne['erreurs'][] = 'Nom et prénom obligatoires.';
        }

        $categorie = isset($valeurs['categorie']) && '' !== $valeurs['categorie'] ? ($categories[self::normaliser($valeurs['categorie'])] ?? null) : $parDefaut;
        if (!$categorie) {
            $ligne['erreurs'][] = isset($valeurs['categorie']) && '' !== $valeurs['categorie']
                ? \sprintf('Catégorie « %s » inconnue (ou hors de vos secteurs).', $valeurs['categorie'])
                : 'Catégorie manquante : choisissez une catégorie par défaut.';
        }
        $v['categorie'] = (string) $categorie?->getId();
        $v['categorieNom'] = (string) $categorie?->getNom();

        foreach (['dateNaissance' => 'Date de naissance', 'dateArrivee' => 'Date d\'arrivée'] as $champ => $libelle) {
            if (!empty($valeurs[$champ])) {
                if ($date = self::date($valeurs[$champ])) {
                    $v[$champ] = $date->format('Y-m-d');
                } else {
                    $ligne['erreurs'][] = \sprintf('%s illisible : « %s » (attendu : JJ/MM/AAAA).', $libelle, $valeurs[$champ]);
                }
            }
        }
        foreach (['poste' => Joueur::POSTES, 'pied' => Joueur::PIEDS] as $champ => $choix) {
            if (!empty($valeurs[$champ])) {
                $trouve = array_values(array_filter($choix, fn (string $c) => self::normaliser($c) === self::normaliser($valeurs[$champ])))[0] ?? null;
                if ($trouve) {
                    $v[$champ] = $trouve;
                } else {
                    $ligne['avertissements'][] = \sprintf('%s « %s » non reconnu, laissé vide.', ucfirst($champ), $valeurs[$champ]);
                }
            }
        }
        foreach (['emailJoueur', 'emailParent1', 'emailParent2'] as $champ) {
            if (!empty($valeurs[$champ])) {
                if (filter_var($valeurs[$champ], \FILTER_VALIDATE_EMAIL)) {
                    $v[$champ] = mb_strtolower($valeurs[$champ]);
                } else {
                    $ligne['avertissements'][] = \sprintf('E-mail « %s » invalide, laissé vide.', $valeurs[$champ]);
                }
            }
        }
        $longueurs = ['lieuNaissance' => 80, 'nationalite' => 40, 'licence' => 20, 'adresse' => 120, 'codePostal' => 10, 'ville' => 100,
            'telJoueur' => 20, 'telPortable1' => 20, 'telPortable2' => 20];
        foreach ($longueurs as $champ => $max) {
            if (!empty($valeurs[$champ])) {
                $v[$champ] = mb_substr($valeurs[$champ], 0, $max);
            }
        }
    }

    private static function date(string $texte): ?\DateTimeImmutable
    {
        if (preg_match('/^\d{5}$/', $texte)) {
            // Date Excel en nombre de jours depuis le 30/12/1899.
            return (new \DateTimeImmutable('1899-12-30'))->modify("+$texte days");
        }
        foreach (['d/m/Y', 'd-m-Y', 'd.m.Y', 'Y-m-d', 'd/m/y'] as $format) {
            $date = \DateTimeImmutable::createFromFormat('!' . $format, $texte);
            if ($date && $date->format($format) === $texte && (int) $date->format('Y') > 1900) {
                return $date;
            }
        }

        return null;
    }

    private static function normaliser(string $texte): string
    {
        $texte = mb_strtolower(trim($texte));
        $texte = strtr($texte, ['à' => 'a', 'â' => 'a', 'ä' => 'a', 'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e', 'î' => 'i', 'ï' => 'i',
            'ô' => 'o', 'ö' => 'o', 'ù' => 'u', 'û' => 'u', 'ü' => 'u', 'ç' => 'c', '°' => ' ', 'º' => ' ']);

        return trim(preg_replace('/[^a-z0-9]+/', ' ', $texte));
    }

    private static function cle(?string $nom, ?string $prenom, ?string $naissance): string
    {
        return self::normaliser((string) $nom) . '|' . self::normaliser((string) $prenom) . '|' . $naissance;
    }
}
