<?php

namespace App\Service;

use App\Entity\Categorie;
use App\Entity\Joueur;
use App\Entity\Mesure;
use App\Entity\ResultatTest;
use App\Entity\TypeTest;
use App\Repository\JoueurRepository;
use App\Repository\ResultatTestRepository;

/**
 * Progression dans le temps : pour chaque test du catalogue (ou mesure : taille, poids), la série des valeurs
 * datées, le premier, le dernier et le meilleur résultat, l'évolution (bon sens ou non selon le test : un temps
 * qui baisse est un progrès), et les coordonnées d'une petite courbe SVG.
 */
class Progression
{
    /** Taille de la courbe (unités SVG). */
    public const LARGEUR = 320;
    public const HAUTEUR = 110;
    private const MARGE_X = 14;
    private const MARGE_Y = 16;
    /** À droite : la place des repères (valeurs haute et basse). */
    public const GOUTTIERE = 40;

    /** Mesures suivies dans le temps : champ => [libellé, unité]. */
    public const MESURES = ['taille' => ['Taille', 'cm'], 'poids' => ['Poids', 'kg']];

    public function __construct(
        private readonly ResultatTestRepository $resultats,
        private readonly JoueurRepository $joueurs,
    ) {
    }

    /**
     * Une série par test passé par le joueur, dans l'ordre du catalogue.
     *
     * @param list<ResultatTest> $resultats
     *
     * @return list<array<string, mixed>>
     */
    public function seriesTests(array $resultats): array
    {
        $parTest = [];
        foreach ($resultats as $r) {
            $parTest[$r->getTest()->getId()]['test'] = $r->getTest();
            $parTest[$r->getTest()->getId()]['points'][] = ['date' => $r->getDate(), 'valeur' => $r->getValeur()];
        }
        uasort($parTest, fn (array $a, array $b) => [$a['test']->getOrdre(), $a['test']->getNom()] <=> [$b['test']->getOrdre(), $b['test']->getNom()]);
        $series = [];
        foreach ($parTest as ['test' => $t, 'points' => $points]) {
            usort($points, fn (array $a, array $b) => $a['date'] <=> $b['date']);
            $series[] = $this->serie((string) $t->getId(), (string) $t->getNom(), (string) $t->getUnite(), $points, $t->isPlusBasMieux()) + ['famille' => $t->getFamille()];
        }

        return $series;
    }

    /**
     * Séries des mesures (taille, poids) : pas de « bon sens », c'est la croissance.
     *
     * @param list<Mesure> $mesures
     *
     * @return list<array<string, mixed>>
     */
    public function seriesMesures(array $mesures): array
    {
        usort($mesures, fn ($a, $b) => $a->getDate() <=> $b->getDate());
        $series = [];
        foreach (self::MESURES as $champ => [$libelle, $unite]) {
            $points = [];
            foreach ($mesures as $m) {
                if (null !== ($v = $m->{'get' . ucfirst($champ)}())) {
                    $points[] = ['date' => $m->getDate(), 'valeur' => (float) $v];
                }
            }
            if ($points) {
                $series[] = ['mieux' => null] + $this->serie($champ, $libelle, $unite, $points, false);
            }
        }

        return $series;
    }

    /**
     * Vue d'équipe : pour chaque joueur actif, le dernier résultat de chaque test et l'évolution depuis
     * le précédent ; le meilleur de l'équipe est repéré.
     *
     * @return array{tests: list<TypeTest>, lignes: list<array{joueur: Joueur, valeurs: array<int, array{valeur: float, date: \DateTimeImmutable, evolution: ?float, mieux: ?bool, meilleur: bool}>}>, testes: int}
     */
    public function equipe(Categorie $categorie): array
    {
        $joueurs = $this->joueurs->findBy(['categorie' => $categorie, 'ancien' => false], ['nom' => 'ASC', 'prenom' => 'ASC']);
        $parJoueur = [];
        $tests = [];
        foreach ($joueurs ? $this->resultats->findBy(['joueur' => $joueurs], ['date' => 'ASC', 'id' => 'ASC']) : [] as $r) {
            $parJoueur[$r->getJoueur()->getId()][$r->getTest()->getId()][] = $r;
            $tests[$r->getTest()->getId()] = $r->getTest();
        }
        uasort($tests, fn (TypeTest $a, TypeTest $b) => [$a->getOrdre(), $a->getNom()] <=> [$b->getOrdre(), $b->getNom()]);
        $lignes = [];
        $meilleurs = [];
        foreach ($joueurs as $j) {
            $valeurs = [];
            foreach ($parJoueur[$j->getId()] ?? [] as $idTest => $serie) {
                $t = $tests[$idTest];
                $dernier = end($serie);
                $v = $dernier->getValeur();
                $evolution = \count($serie) > 1 ? $v - $serie[\count($serie) - 2]->getValeur() : null;
                $valeurs[$idTest] = ['valeur' => $v, 'date' => $dernier->getDate(), 'evolution' => $evolution, 'mieux' => self::mieux($evolution, $t->isPlusBasMieux()), 'meilleur' => false];
                if (!isset($meilleurs[$idTest]) || ($t->isPlusBasMieux() ? $v < $meilleurs[$idTest] : $v > $meilleurs[$idTest])) {
                    $meilleurs[$idTest] = $v;
                }
            }
            $lignes[] = ['joueur' => $j, 'valeurs' => $valeurs];
        }
        foreach ($lignes as &$l) {
            foreach ($l['valeurs'] as $idTest => &$val) {
                $val['meilleur'] = $val['valeur'] === $meilleurs[$idTest];
            }
            unset($val);
        }
        unset($l);

        return ['tests' => array_values($tests), 'lignes' => $lignes, 'testes' => \count(array_filter($lignes, fn (array $l) => $l['valeurs']))];
    }

    /** Évolution dans le bon sens ? null si inchangée ou inconnue. */
    public static function mieux(?float $evolution, bool $plusBasMieux): ?bool
    {
        if (null === $evolution || abs($evolution) < 1e-9) {
            return null;
        }

        return $plusBasMieux ? $evolution < 0 : $evolution > 0;
    }

    /** @param list<array{date: \DateTimeImmutable, valeur: float}> $points dans l'ordre des dates */
    public function serie(string $champ, string $libelle, string $unite, array $points, bool $plusBasMieux = false): array
    {
        $premier = $points[0];
        $dernier = end($points);
        $evolution = \count($points) > 1 ? $dernier['valeur'] - $premier['valeur'] : null;
        $valeurs = array_column($points, 'valeur');
        [$min, $max] = [min($valeurs), max($valeurs)];
        $ecart = $max - $min ?: max(1.0, abs($max) * 0.1);
        $t0 = $premier['date']->getTimestamp();
        $duree = max(1, $dernier['date']->getTimestamp() - $t0);
        $largeur = self::LARGEUR - self::MARGE_X - self::GOUTTIERE;
        $hauteur = self::HAUTEUR - 2 * self::MARGE_Y;
        foreach ($points as &$p) {
            // Un seul point : au milieu.
            $p['x'] = round(self::MARGE_X + (\count($points) > 1 ? ($p['date']->getTimestamp() - $t0) / $duree * $largeur : $largeur / 2), 1);
            $p['y'] = round(self::MARGE_Y + ($max === $min ? $hauteur / 2 : (1 - ($p['valeur'] - $min) / $ecart) * $hauteur), 1);
        }
        unset($p);

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
            'mieux' => self::mieux($evolution, $plusBasMieux),
            'trace' => implode(' ', array_map(fn (array $p) => $p['x'] . ',' . $p['y'], $points)),
            'min' => $min,
            'max' => $max,
        ];
    }
}
