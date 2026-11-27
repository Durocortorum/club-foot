<?php

namespace App\Service;

use App\Entity\Categorie;
use App\Entity\Exercice;
use App\Entity\Seance;

/**
 * Séance automatique : à partir de l'équipe, de la durée et des thèmes choisis, compose une séance avec les exercices
 * types de la bibliothèque (niveau de l'équipe d'abord) : échauffement, 2 ou 3 exercices sur les thèmes, jeu final.
 * La séance est ensuite modifiable comme une autre (exercices, durées, consignes, schémas).
 */
class GenerateurSeance
{
    /** Thèmes proposés dans le formulaire. */
    public const THEMES = [
        'passes' => 'Passes et conservation',
        'finition' => 'Finition et frappe',
        'duels' => 'Duels (1 contre 1)',
        'defense' => 'Défense et placement',
        'transition' => 'Transition et contre-attaque',
        'pressing' => 'Pressing et récupération',
        'cpa' => 'Coups de pied arrêtés',
        'dribble' => 'Conduite et dribble',
        'physique' => 'Physique avec ballon',
    ];

    public const DUREES = [60, 75, 90, 105];

    /**
     * Rôle de chaque exercice type (e : échauffement, c : corps de séance, j : jeu final) et ses thèmes.
     * Un exercice ajouté plus tard à la bibliothèque sans classement compte comme exercice de corps de séance.
     */
    private const CLASSEMENT = [
        // École de foot
        'slalom' => ['e', ['dribble']],
        'demenageurs' => ['e', ['dribble', 'physique']],
        'portes' => ['e', ['passes']],
        'carre_passes' => ['e', ['passes']],
        'crocodiles' => ['e', ['dribble', 'duels']],
        'conduite_tir' => ['c', ['finition', 'dribble']],
        'beret' => ['c', ['duels', 'physique']],
        'miroir' => ['c', ['duels', 'dribble']],
        'deux_contre_un' => ['c', ['finition', 'duels', 'passes']],
        'match3c3' => ['j', ['duels', 'finition', 'passes']],
        // Préformation
        'rondo4c1' => ['e', ['passes', 'pressing']],
        'losange' => ['e', ['passes']],
        'conservation' => ['c', ['passes', 'pressing']],
        'centre' => ['c', ['finition']],
        'duel1c1' => ['c', ['duels', 'finition', 'defense']],
        'controle_frappe' => ['c', ['finition', 'passes']],
        'couverture' => ['c', ['defense', 'duels']],
        'appel_profondeur' => ['c', ['passes', 'finition']],
        'retrait_frappe' => ['c', ['finition']],
        'quatre_minibuts' => ['j', ['passes', 'transition']],
        // Formation
        'rondo5c2' => ['e', ['passes', 'pressing']],
        'transition' => ['c', ['transition', 'defense']],
        'possession' => ['c', ['passes', 'pressing']],
        'finition2c1' => ['c', ['finition', 'duels']],
        'une_deux' => ['c', ['passes', 'finition']],
        'sortie_balle' => ['c', ['passes', 'pressing']],
        'defense_coulisser' => ['c', ['defense']],
        'contre_pressing' => ['c', ['pressing', 'transition']],
        'coup_franc' => ['c', ['cpa', 'finition']],
        'trois_contre_trois' => ['j', ['duels', 'finition', 'physique']],
        // Seniors
        'passes_y' => ['e', ['passes']],
        'troisieme_homme' => ['c', ['passes']],
        'positionnel' => ['c', ['passes', 'pressing']],
        'fractionne' => ['c', ['physique']],
        'corner' => ['c', ['cpa']],
        'contre3c2' => ['c', ['transition', 'finition']],
        'pressing_haut' => ['c', ['pressing', 'defense']],
        'repli_defensif' => ['c', ['defense', 'transition']],
        'chevauchement' => ['c', ['finition', 'passes']],
        'jeu_long' => ['c', ['passes', 'duels']],
    ];

    /** Groupes de la bibliothèque du plus jeune au plus âgé (pour chercher au niveau voisin). */
    private const ORDRE_GROUPES = ['ecole_foot', 'preformation', 'formation', 'seniors'];

    public function __construct(private readonly BibliothequeExercices $bibliotheque)
    {
    }

    /** Début de la ligne ajoutée aux consignes : répartition des joueurs présents (remplacée à chaque adaptation). */
    public const LIGNE_EFFECTIF = 'Effectif du jour : ';

    /**
     * Compose la séance (non enregistrée).
     *
     * @param list<string> $themes  clés de THEMES (au moins une)
     * @param list<string> $recents titres des exercices des dernières séances de l'équipe (évités si possible)
     * @param int|null     $joueurs joueurs présents (gardiens compris) : exercices trop gros écartés, répartition indiquée
     */
    public function composer(Categorie $categorie, \DateTimeImmutable $date, int $duree, array $themes, ?string $objectif, array $recents = [], ?int $joueurs = null): Seance
    {
        $themes = array_values(array_intersect(array_keys(self::THEMES), $themes)) ?: ['passes'];
        // Temps : échauffement ~15 %, jeu final ~20 %, le reste pour le cœur de séance.
        $echauffementMin = $this->arrondi($duree * .15, 10);
        $jeuMin = $this->arrondi($duree * .2, 10);
        $corpsMin = $duree - $echauffementMin - $jeuMin;
        $nbCorps = $corpsMin > 40 ? 3 : 2;

        $choisis = [];
        $prendre = function (string $role, ?string $theme = null) use ($categorie, $themes, $recents, $joueurs, &$choisis): ?string {
            $cle = $this->choisir($categorie, $role, $themes, $theme, $choisis, $recents, $joueurs);
            if ($cle) {
                $choisis[] = $cle;
            }

            return $cle;
        };

        $echauffement = $prendre('e');
        // Cœur de séance : un exercice par thème demandé (tour à tour), puis les mieux adaptés.
        $corps = [];
        for ($tour = 0; \count($corps) < $nbCorps && $tour < $nbCorps * 2; ++$tour) {
            if ($cle = $prendre('c', $themes[$tour % \count($themes)])) {
                $corps[] = $cle;
            }
        }
        while (\count($corps) < $nbCorps && ($cle = $prendre('c'))) {
            $corps[] = $cle;
        }
        $jeu = $prendre('j');

        $libelles = array_map(fn ($t) => self::THEMES[$t], $themes);
        $seance = (new Seance())->setDate($date)->setCategorie($categorie)
            ->setTheme(mb_substr(implode(', ', $libelles), 0, 120))
            ->setObjectifs(trim(($objectif ? $objectif . "\n\n" : '') . \sprintf('Séance préparée automatiquement (%d min%s) : %s. Adaptez les exercices, les durées et les consignes à votre groupe.',
                $duree, $joueurs ? ', ' . $joueurs . ' joueurs' : '', mb_strtolower(implode(', ', $libelles)))));

        if ($echauffement) {
            $seance->addExercice($this->exercice($echauffement, 'Échauffement – ', $echauffementMin));
        } else {
            $corpsMin += $echauffementMin;
        }
        $parExercice = $this->repartir($corpsMin, max(1, \count($corps)));
        foreach ($corps as $i => $cle) {
            $seance->addExercice($this->exercice($cle, '', $parExercice[$i]));
        }
        $seance->addExercice($jeu ? $this->exercice($jeu, 'Jeu final – ', $jeuMin) : $this->matchATheme($libelles, $jeuMin));
        if ($joueurs) {
            $this->noterEffectif($seance, $joueurs);
        }

        return $seance;
    }

    /**
     * Adapte une séance au nombre de joueurs présents (absents de dernière minute, renforts) : même thème, mêmes
     * durées ; un exercice de la bibliothèque trop gros pour l'effectif est remplacé par un autre du même rôle et
     * des mêmes thèmes ; la répartition des joueurs est réécrite dans chaque exercice.
     *
     * @return list<array{avant: string, apres: string}> exercices remplacés
     */
    public function adapter(Seance $seance, int $joueurs): array
    {
        $tous = $this->bibliotheque->tous();
        $themes = $this->themesDeLaSeance($seance);
        $dansLaSeance = array_filter(array_map(fn (Exercice $e) => $this->cleDe($e), $seance->getExercices()->toArray()));
        $remplaces = [];
        foreach ($seance->getExercices() as $e) {
            $cle = $this->cleDe($e);
            if (!$cle || $this->taille($cle) <= $joueurs) {
                continue;
            }
            $themesExercice = array_values(array_intersect($this->themesDe($cle), $themes)) ?: $this->themesDe($cle);
            $nouvelle = $this->choisir($seance->getCategorie(), $this->role($cle), $themesExercice ?: $themes, null, $dansLaSeance, [], $joueurs);
            if (!$nouvelle && 'j' === $this->role($cle)) {
                // Aucun jeu de la bibliothèque à ce nombre : match à thème, sans nombre imposé.
                $match = $this->matchATheme(array_map(fn ($t) => self::THEMES[$t], $themes ?: ['passes']), (int) $e->getDuree());
                $remplaces[] = ['avant' => (string) $e->getTitre(), 'apres' => (string) $match->getTitre()];
                $e->setTitre($match->getTitre())->setConsignes($match->getConsignes())->setSchema($match->getSchema());
                continue;
            }
            if (!$nouvelle) {
                continue;
            }
            $dansLaSeance[] = $nouvelle;
            $prefixe = preg_match('/^(Échauffement|Jeu final) – /u', (string) $e->getTitre(), $m) ? $m[0] : '';
            $remplaces[] = ['avant' => (string) $e->getTitre(), 'apres' => $prefixe . $tous[$nouvelle]['titre']];
            $e->setTitre(mb_substr($prefixe . $tous[$nouvelle]['titre'], 0, 120))
                ->setConsignes($tous[$nouvelle]['consignes'])->setSchema($tous[$nouvelle]['schema']);
        }
        $this->noterEffectif($seance, $joueurs);

        return $remplaces;
    }

    /** Écrit (ou réécrit) dans chaque exercice la répartition des joueurs présents. */
    private function noterEffectif(Seance $seance, int $joueurs): void
    {
        foreach ($seance->getExercices() as $e) {
            $consignes = preg_replace('/\n*' . preg_quote(self::LIGNE_EFFECTIF, '/') . '.*$/us', '', (string) $e->getConsignes());
            $cle = $this->cleDe($e);
            $jeu = str_starts_with((string) $e->getTitre(), 'Jeu final – ') || ($cle && 'j' === $this->role($cle));
            $e->setConsignes(trim($consignes . "\n\n" . self::LIGNE_EFFECTIF . $this->repartition($jeu ? null : ($cle ? $this->taille($cle) : null), $joueurs)));
        }
    }

    /**
     * Répartition lisible : ateliers de la taille de l'exercice (joueurs en plus en rotation), ou deux équipes pour un jeu.
     *
     * @param int|null $taille joueurs par atelier ; null : jeu (deux équipes)
     */
    public function repartition(?int $taille, int $joueurs): string
    {
        if (null === $taille) {
            // Grand groupe : deux matchs en même temps plutôt que des joueurs qui attendent sur le bord.
            if ($joueurs >= 20) {
                $a = intdiv($joueurs, 2);

                return \sprintf('%d joueurs : 2 matchs en même temps sur 2 terrains réduits (%d et %d joueurs), chacun en 2 équipes ; on mélange les équipes à la mi-temps.', $joueurs, $a, $joueurs - $a);
            }
            $gardiens = $joueurs >= 12 ? 2 : 0;
            $champ = $joueurs - $gardiens;
            $equipe = intdiv($champ, 2);

            return \sprintf('%d joueurs : %s2 équipes de %d%s%s.', $joueurs, $gardiens ? '2 gardiens et ' : '', $equipe,
                $champ % 2 ? ' et un joker qui joue avec l\'équipe qui a le ballon' : '',
                $joueurs < 10 ? ' ; terrain réduit (environ 30 × 20 m), mini-buts ou buts sans gardien' : '');
        }
        if ($joueurs < $taille) {
            return \sprintf('%d joueurs pour un exercice prévu à %d : un seul groupe, enlever un défenseur ou un appui, ou faire jouer l\'éducateur.', $joueurs, $taille);
        }
        // Exercice en colonnes (peu de joueurs à la fois) : ateliers identiques de 4 à 6, pour ne pas attendre son tour.
        if ($taille < 4) {
            $ateliers = max(1, (int) ceil($joueurs / 6));
            $min = intdiv($joueurs, $ateliers);

            return \sprintf('%d joueurs : %d atelier%s identique%s de %s joueurs (le même exercice installé %d fois), pour que personne n\'attende plus d\'un ou deux passages.',
                $joueurs, $ateliers, $ateliers > 1 ? 's' : '', $ateliers > 1 ? 's' : '', $joueurs % $ateliers ? $min . ' ou ' . ($min + 1) : (string) $min, $ateliers);
        }
        $ateliers = intdiv($joueurs, $taille);
        $reste = $joueurs % $taille;

        return \sprintf('%d joueurs : %d atelier%s de %d%s.', $joueurs, $ateliers, $ateliers > 1 ? 's' : '', $taille,
            $reste ? \sprintf(' ; %d joueur%s en plus : %s', $reste, $reste > 1 ? 's' : '',
                $reste >= $taille / 2 ? 'un atelier de plus en jouant à moins (enlever un défenseur ou un appui)' : 'en appui ou joker, rotation toutes les 2 minutes') : '');
    }

    /**
     * Tire un exercice de la bibliothèque : rôle voulu, niveau de l'équipe ou voisin, thème (si donné), pas déjà pris,
     * de préférence pas fait récemment, et assez petit pour l'effectif (sauf le jeu final, qui s'adapte).
     *
     * @param list<string> $themes  thèmes de la séance (favorisés)
     * @param list<string> $exclus  clés déjà prises
     * @param list<string> $recents titres à éviter
     */
    private function choisir(Categorie $categorie, string $role, array $themes, ?string $theme, array $exclus, array $recents, ?int $joueurs): ?string
    {
        $tous = $this->bibliotheque->tous();
        $position = array_search($this->groupeDe($categorie), self::ORDRE_GROUPES, true);
        $rang = fn (string $cle) => abs(array_search($tous[$cle]['secteur'], self::ORDRE_GROUPES, true) - $position);
        $score = fn (string $cle) => \count(array_intersect($this->themesDe($cle), $themes));
        $candidats = array_values(array_filter(array_keys($tous), fn ($cle) => !\in_array($cle, $exclus, true) && $rang($cle) <= 1
            && $role === $this->role($cle) && (null === $theme || \in_array($theme, $this->themesDe($cle), true))));
        if ($joueurs) {
            $candidats = array_values(array_filter($candidats, fn ($cle) => $this->taille($cle) <= $joueurs));
        }
        // Pas un exercice des dernières séances de l'équipe, s'il y a d'autres choix.
        $recents = array_map(fn ($t) => mb_strtolower(trim($t)), $recents);
        $candidats = array_values(array_filter($candidats, fn ($cle) => !\in_array(mb_strtolower($tous[$cle]['titre']), $recents, true))) ?: $candidats;
        if (!$candidats) {
            return null;
        }
        // Tirage pondéré : plus de thèmes demandés et niveau de l'équipe favorisés, mais le niveau voisin et
        // les exercices proches ont leur chance (séance différente à chaque demande).
        $poids = array_map(fn ($cle) => (1 + 2 * $score($cle)) * (0 === $rang($cle) ? 3 : 1), $candidats);
        $tirage = mt_rand(1, array_sum($poids));
        foreach ($candidats as $i => $cle) {
            if (($tirage -= $poids[$i]) <= 0) {
                return $cle;
            }
        }

        return null;
    }

    private function role(string $cle): string
    {
        return $this->bibliotheque->tous()[$cle]['role'] ?? self::CLASSEMENT[$cle][0] ?? 'c';
    }

    /** @return list<string> */
    private function themesDe(string $cle): array
    {
        return $this->bibliotheque->tous()[$cle]['themes'] ?? self::CLASSEMENT[$cle][1] ?? [];
    }

    /**
     * Joueurs nécessaires pour un atelier ou un jeu (hors gardiens et éducateur) : lu dans le titre (« 5 contre 2 »,
     * « 6 contre 6 + 3 », « 4+4 contre 2 »), puis dans l'organisation (« 6 contre 6 »), sinon compté sur le schéma.
     */
    public function taille(string $cle): int
    {
        $e = $this->bibliotheque->tous()[$cle];
        $organisation = strtok((string) $e['consignes'], "\n");
        foreach ([$e['titre'], $organisation] as $texte) {
            if (preg_match('/(\d+)\s*\+\s*(\d+) contre (\d+)/u', $texte, $m)) {
                return (int) $m[1] + (int) $m[2] + (int) $m[3];
            }
            if (preg_match('/(\d+) contre (\d+)(?: \+ (\d+))?/u', $texte, $m)) {
                return (int) $m[1] + (int) $m[2] + (int) ($m[3] ?? 0);
            }
        }

        return max(2, \count(array_filter($e['schema']['objets'] ?? [], fn ($o) => 'joueur' === $o['t'] && !\in_array($o['n'] ?? '', ['G', 'E'], true))));
    }

    /** Clé de bibliothèque d'un exercice de séance (par son titre, sans « Échauffement – » ni « Jeu final – »). */
    private function cleDe(Exercice $e): ?string
    {
        $titre = mb_strtolower(preg_replace('/^(Échauffement|Jeu final) – /u', '', (string) $e->getTitre()));
        foreach ($this->bibliotheque->tous() as $cle => $type) {
            if (mb_strtolower($type['titre']) === $titre) {
                return $cle;
            }
        }

        return null;
    }

    /** @return list<string> clés de THEMES retrouvées dans le thème de la séance */
    public function themesDeLaSeance(Seance $seance): array
    {
        $theme = mb_strtolower((string) $seance->getTheme());

        return array_values(array_filter(array_keys(self::THEMES), fn ($cle) => str_contains($theme, mb_strtolower(self::THEMES[$cle]))));
    }

    private function exercice(string $cle, string $prefixe, int $minutes): Exercice
    {
        $e = $this->bibliotheque->creer($cle);

        return $e->setTitre(mb_substr($prefixe . $e->getTitre(), 0, 120))->setDuree($minutes);
    }

    /** Jeu final quand la bibliothèque n'en a pas pour ce niveau : match à thème. */
    private function matchATheme(array $libelles, int $minutes): Exercice
    {
        return (new Exercice())->setTitre('Jeu final – Match à thème')->setDuree($minutes)
            ->setConsignes(implode("\n", [
                'Organisation : match sur demi-terrain ou terrain réduit, équipes égales, gardiens si possible.',
                'Déroulement : jeu libre avec une consigne liée au thème du jour (' . mb_strtolower(implode(', ', $libelles)) . ').',
                'Variantes : but qui compte double quand il vient du thème travaillé ; touches de balle limitées.',
                'Points clés : retrouver dans le match ce qui a été travaillé pendant la séance.',
            ]))
            ->setSchema(['terrain' => 'demi', 'objets' => []]);
    }

    /** Groupe de la bibliothèque correspondant au secteur de la catégorie (préformation par défaut). */
    private function groupeDe(Categorie $categorie): string
    {
        foreach (BibliothequeExercices::GROUPES as $cle => $g) {
            if (\in_array($categorie->getSecteur(), $g['secteurs'], true)) {
                return $cle;
            }
        }

        return 'preformation';
    }

    private function arrondi(float $minutes, int $minimum): int
    {
        return max($minimum, (int) (round($minutes / 5) * 5));
    }

    /** @return list<int> minutes par exercice, multiples de 5, dont la somme fait le total */
    private function repartir(int $total, int $nombre): array
    {
        $base = (int) (floor($total / $nombre / 5) * 5);
        $parts = array_fill(0, $nombre, $base);
        for ($reste = $total - $base * $nombre, $i = 0; $reste > 0; $reste -= min(5, $reste), ++$i) {
            $parts[$i % $nombre] += min(5, $reste);
        }

        return $parts;
    }
}
