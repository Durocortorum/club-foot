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

    /**
     * Compose la séance (non enregistrée).
     *
     * @param list<string> $themes clés de THEMES (au moins une)
     */
    public function composer(Categorie $categorie, \DateTimeImmutable $date, int $duree, array $themes, ?string $objectif): Seance
    {
        $themes = array_values(array_intersect(array_keys(self::THEMES), $themes)) ?: ['passes'];
        $groupe = $this->groupeDe($categorie);
        $tous = $this->bibliotheque->tous();
        // Exercices du niveau de l'équipe d'abord, puis des niveaux voisins (le plus proche en premier).
        $rangs = [];
        $position = array_search($groupe, self::ORDRE_GROUPES, true);
        foreach ($tous as $cle => $e) {
            $rangs[$cle] = abs(array_search($e['secteur'], self::ORDRE_GROUPES, true) - $position);
        }
        $role = fn (string $cle) => self::CLASSEMENT[$cle][0] ?? 'c';
        $themesDe = fn (string $cle) => self::CLASSEMENT[$cle][1] ?? [];
        $score = fn (string $cle) => \count(array_intersect($themesDe($cle), $themes));

        // Temps : échauffement ~15 %, jeu final ~20 %, le reste pour le cœur de séance.
        $echauffementMin = $this->arrondi($duree * .15, 10);
        $jeuMin = $this->arrondi($duree * .2, 10);
        $corpsMin = $duree - $echauffementMin - $jeuMin;
        $nbCorps = $corpsMin > 40 ? 3 : 2;

        $choisis = [];
        $prendre = function (callable $filtre, ?string $theme = null) use ($tous, $rangs, $score, $themesDe, &$choisis): ?string {
            $candidats = array_filter(array_keys($tous), fn ($cle) => !\in_array($cle, $choisis, true) && $filtre($cle)
                && (null === $theme || \in_array($theme, $themesDe($cle), true)));
            if (!$candidats) {
                return null;
            }
            // Niveau le plus proche, puis le plus de thèmes demandés ; au hasard entre égaux (séance différente à chaque fois).
            shuffle($candidats);
            usort($candidats, fn ($a, $b) => [$rangs[$a], -$score($a)] <=> [$rangs[$b], -$score($b)]);
            if ($rangs[$candidats[0]] > 1) {
                return null; // trop loin du niveau de l'équipe
            }
            $choisis[] = $candidats[0];

            return $candidats[0];
        };

        $echauffement = $prendre(fn ($c) => 'e' === $role($c) && $score($c) > 0) ?? $prendre(fn ($c) => 'e' === $role($c));
        // Cœur de séance : un exercice par thème demandé (tour à tour), puis les mieux adaptés.
        $corps = [];
        for ($tour = 0; \count($corps) < $nbCorps && $tour < $nbCorps * 2; ++$tour) {
            $theme = $themes[$tour % \count($themes)];
            if ($cle = $prendre(fn ($c) => 'c' === $role($c), $theme)) {
                $corps[] = $cle;
            }
        }
        while (\count($corps) < $nbCorps && ($cle = $prendre(fn ($c) => 'c' === $role($c) && $score($c) > 0) ?? $prendre(fn ($c) => 'c' === $role($c)))) {
            $corps[] = $cle;
        }
        $jeu = $prendre(fn ($c) => 'j' === $role($c));

        $libelles = array_map(fn ($t) => self::THEMES[$t], $themes);
        $seance = (new Seance())->setDate($date)->setCategorie($categorie)
            ->setTheme(mb_substr(implode(', ', $libelles), 0, 120))
            ->setObjectifs(trim(($objectif ? $objectif . "\n\n" : '') . \sprintf('Séance préparée automatiquement (%d min) : %s. Adaptez les exercices, les durées et les consignes à votre groupe.', $duree, mb_strtolower(implode(', ', $libelles)))));

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

        return $seance;
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
