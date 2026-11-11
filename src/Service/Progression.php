<?php

namespace App\Service;

use App\Entity\Categorie;
use App\Entity\Joueur;
use App\Entity\Mesure;
use App\Entity\TestPhysique;
use App\Repository\JoueurRepository;
use App\Repository\TestPhysiqueRepository;

/**
 * Progression dans le temps : pour chaque épreuve (tests physiques) ou mesure (taille, poids),
 * la série des valeurs datées, le premier et le dernier résultat, l'évolution, et les coordonnées
 * d'une petite courbe SVG. Pour un temps (secondes), plus bas est meilleur.
 */
class Progression
{
    /** Taille de la courbe (unités SVG). */
    public const LARGEUR = 320;
    public const HAUTEUR = 110;
    private const MARGE_X = 14;
    private const MARGE_Y = 16;
    public const GOUTTIERE = 40;

    /** Mesures suivies dans le temps : champ => [libellé, unité]. */
    public const MESURES = ['taille' => ['Taille', 'cm'], 'poids' => ['Poids', 'kg']];

    public function __construct(
        private readonly TestPhysiqueRepository $tests,
        private readonly JoueurRepository $joueurs,
    ) {
    }

    /**
     * Séries d'un joueur : une par épreuve (ou mesure) qui a au moins une valeur.
     *
     * @param list<TestPhysique|Mesure>          $releves
     * @param array<string, array{string, string}> $champs champ => [libellé, unité]
     *
     * @return list<array<string, mixed>>
     */
    public function series(array $releves, array $champs): array
    {
        usort($releves, fn ($a, $b) => $a->getDate() <=> $b->getDate());
        $series = [];
        foreach ($champs as $champ => [$libelle, $unite]) {
            $points = [];
            foreach ($releves as $r) {
                $v = $r->{'get' . ucfirst($champ)}();
                if (null !== $v) {
                    $points[] = ['date' => $r->getDate(), 'valeur' => (float) $v];
                }
            }
            if ($points) {
                $series[] = $this->serie($champ, $libelle, $unite, $points);
            }
        }

        return $series;
    }

    /**
     * Vue d'équipe : pour chaque joueur actif, le dernier résultat de chaque épreuve et l'évolution
     * depuis le précédent ; le meilleur de l'équipe est repéré.
     *
     * @return array{epreuves: array<string, array{string, string}>, lignes: list<array{joueur: Joueur, valeurs: array<string, array{valeur: float, date: \DateTimeImmutable, evolution: ?float, mieux: ?bool, meilleur: bool}>}>, testes: int}
     */
    public function equipe(Categorie $categorie): array
    {
        $joueurs = $this->joueurs->findBy(['categorie' => $categorie, 'ancien' => false], ['nom' => 'ASC', 'prenom' => 'ASC']);
        $parJoueur = [];
        foreach ($joueurs ? $this->tests->findBy(['joueur' => $joueurs], ['date' => 'ASC']) : [] as $t) {
            $parJoueur[$t->getJoueur()->getId()][] = $t;
        }
        $lignes = [];
        $meilleurs = [];
        foreach ($joueurs as $j) {
            $valeurs = [];
            foreach (TestPhysique::EPREUVES as $champ => [, $unite]) {
                $serie = array_values(array_filter($parJoueur[$j->getId()] ?? [], fn (TestPhysique $t) => null !== $t->{'get' . ucfirst($champ)}()));
                if (!$serie) {
                    continue;
                }
                $dernier = end($serie);
                $v = (float) $dernier->{'get' . ucfirst($champ)}();
                $precedent = \count($serie) > 1 ? (float) $serie[\count($serie) - 2]->{'get' . ucfirst($champ)}() : null;
                $evolution = null !== $precedent ? $v - $precedent : null;
                $valeurs[$champ] = ['valeur' => $v, 'date' => $dernier->getDate(), 'evolution' => $evolution, 'mieux' => self::mieux($evolution, $unite), 'meilleur' => false];
                $plusBasMieux = 's' === $unite;
                if (!isset($meilleurs[$champ]) || ($plusBasMieux ? $v < $meilleurs[$champ] : $v > $meilleurs[$champ])) {
                    $meilleurs[$champ] = $v;
                }
            }
            $lignes[] = ['joueur' => $j, 'valeurs' => $valeurs];
        }
        foreach ($lignes as &$l) {
            foreach ($l['valeurs'] as $champ => &$val) {
                $val['meilleur'] = $val['valeur'] === $meilleurs[$champ];
            }
            unset($val);
        }
        unset($l);

        return [
            'epreuves' => array_filter(TestPhysique::EPREUVES, fn (string $champ) => isset($meilleurs[$champ]), \ARRAY_FILTER_USE_KEY),
            'lignes' => $lignes,
            'testes' => \count(array_filter($lignes, fn (array $l) => $l['valeurs'])),
        ];
    }

    /** Évolution dans le bon sens ? Un temps qui baisse, une distance, une vitesse ou une hauteur qui monte. null si inchangée ou inconnue. */
    public static function mieux(?float $evolution, string $unite): ?bool
    {
        if (null === $evolution || abs($evolution) < 1e-9) {
            return null;
        }

        return 's' === $unite ? $evolution < 0 : $evolution > 0;
    }

    /** @param list<array{date: \DateTimeImmutable, valeur: float}> $points */
    public function serie(string $champ, string $libelle, string $unite, array $points): array
    {
        $premier = $points[0];
        $dernier = end($points);
        $evolution = \count($points) > 1 ? $dernier['valeur'] - $premier['valeur'] : null;
        $valeurs = array_column($points, 'valeur');
        [$min, $max] = [min($valeurs), max($valeurs)];
        $ecart = $max - $min ?: max(1.0, abs($max) * 0.1);
        $t0 = $premier['date']->getTimestamp();
        $duree = max(1, $dernier['date']->getTimestamp() - $t0);
        // À droite : la place des repères (valeurs haute et basse).
        $largeur = self::LARGEUR - self::MARGE_X - self::GOUTTIERE;
        $hauteur = self::HAUTEUR - 2 * self::MARGE_Y;
        foreach ($points as &$p) {
            // Un seul point : au milieu.
            $p['x'] = round(self::MARGE_X + (\count($points) > 1 ? ($p['date']->getTimestamp() - $t0) / $duree * $largeur : $largeur / 2), 1);
            $p['y'] = round(self::MARGE_Y + ($max === $min ? $hauteur / 2 : (1 - ($p['valeur'] - $min) / $ecart) * $hauteur), 1);
        }
        unset($p);
        $plusBasMieux = 's' === $unite;

        return [
            'champ' => $champ,
            'libelle' => $libelle,
            'unite' => $unite,
            'points' => $points,
            'premier' => $premier,
            'dernier' => $dernier,
            'meilleur' => $plusBasMieux ? $min : $max,
            'evolution' => $evolution,
            'pourcentage' => null !== $evolution && $premier['valeur'] ? $evolution / $premier['valeur'] * 100 : null,
            'mieux' => self::mieux($evolution, $unite),
            'trace' => implode(' ', array_map(fn (array $p) => $p['x'] . ',' . $p['y'], $points)),
            'min' => $min,
            'max' => $max,
        ];
    }
}
