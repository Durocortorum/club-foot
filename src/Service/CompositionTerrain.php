<?php

namespace App\Service;

use App\Entity\Participation;
use App\Entity\Rencontre;

/**
 * Schéma de composition : places des titulaires sur le terrain (x de gauche à droite, y de notre but
 * vers le but adverse, en %). Places choisies à la main d'abord, sinon d'après le système de jeu et le poste.
 */
class CompositionTerrain
{
    /** Place habituelle de chaque poste (pour l'attribuer à la place du système la plus proche). */
    private const POSTES = [
        'Gardien' => [50, 6],
        'Latéral droit' => [86, 24], 'Latéral gauche' => [14, 24],
        'Défenseur central' => [50, 20], 'Défenseur central droit' => [63, 20], 'Défenseur central gauche' => [37, 20],
        'Milieu défensif' => [50, 38], 'Milieu défensif axe' => [50, 38], 'Milieu défensif droit' => [65, 38], 'Milieu défensif gauche' => [35, 38],
        'Milieu relayeur' => [50, 50], 'Milieu relayeur droit' => [68, 50], 'Milieu relayeur gauche' => [32, 50],
        'Milieu offensif' => [50, 64], 'Milieu offensif axe' => [50, 64], 'Milieu offensif droit' => [78, 64], 'Milieu offensif gauche' => [22, 64],
        'Milieu excentré droit' => [88, 55], 'Milieu excentré gauche' => [12, 55],
        'Attaquant' => [50, 82],
    ];

    /**
     * @return array{titulaires: list<array{participation: Participation, x: float, y: float}>, remplacants: list<Participation>}
     */
    public function placer(Rencontre $rencontre): array
    {
        $titulaires = $remplacants = [];
        foreach ($rencontre->getParticipations() as $p) {
            if (!$p->getJoueur()) {
                continue;
            }
            $titulaire = null !== $p->getNumero() && $p->getNumero() <= 11 && 'Non utilisé' !== $p->getPoste();
            $titulaire ? $titulaires[] = $p : $remplacants[] = $p;
        }
        usort($titulaires, fn (Participation $a, Participation $b) => $a->getNumero() <=> $b->getNumero());
        usort($remplacants, fn (Participation $a, Participation $b) => [$a->getNumero() ?? 99, $a->getJoueur()->getNom()] <=> [$b->getNumero() ?? 99, $b->getJoueur()->getNom()]);

        $places = $this->placesDuSysteme($rencontre->getSysteme(), \count($titulaires));
        $parJoueur = $this->attribuer($titulaires, $places);
        $choisies = $rencontre->getPlacements();

        return [
            'titulaires' => array_map(function (Participation $p) use ($parJoueur, $choisies) {
                [$x, $y] = $choisies[$p->getJoueur()->getId()] ?? $parJoueur[$p->getJoueur()->getId()];

                return ['participation' => $p, 'x' => $x, 'y' => $y];
            }, $titulaires),
            'remplacants' => $remplacants,
        ];
    }

    /**
     * Places du système : le gardien, puis chaque ligne de joueurs de la défense à l'attaque.
     *
     * @return list<array{0: float, 1: float}>
     */
    public function placesDuSysteme(?string $systeme, int $nombre): array
    {
        $lignes = [];
        if ($systeme && str_contains($systeme, 'losange')) {
            $lignes = [4, 1, 2, 1, 2];
        } elseif ($systeme && preg_match_all('/\d/', $systeme, $m)) {
            $lignes = array_map('intval', $m[0]);
        }
        // Sans système (ou s'il ne correspond pas au nombre de titulaires) : lignes devinées.
        if (array_sum($lignes) !== $nombre - 1) {
            $lignes = match (true) {
                $nombre >= 11 => [4, 4, 2],
                10 === $nombre => [4, 3, 2],
                9 === $nombre => [3, 3, 2],
                8 === $nombre => [3, 3, 1],
                default => [max(0, $nombre - 1)],
            };
        }

        $places = [[50.0, 6.0]];
        $n = \count($lignes);
        foreach ($lignes as $i => $joueurs) {
            $y = $n > 1 ? 22 + $i * (60 / ($n - 1)) : 50;
            for ($k = 0; $k < $joueurs; ++$k) {
                $places[] = [round((100 / ($joueurs + 1)) * ($k + 1), 1), round($y, 1)];
            }
        }

        return \array_slice($places, 0, max(1, $nombre));
    }

    /**
     * Chaque titulaire avec un poste connu prend la place libre la plus proche de ce poste (les plus proches d'abord) ;
     * les autres remplissent les places restantes dans l'ordre des numéros.
     *
     * @param list<Participation>                 $titulaires
     * @param list<array{0: float, 1: float}> $places
     *
     * @return array<int, array{0: float, 1: float}> id du joueur => place
     */
    private function attribuer(array $titulaires, array $places): array
    {
        $couples = [];
        foreach ($titulaires as $p) {
            if ($cible = self::POSTES[$p->getPoste()] ?? null) {
                foreach ($places as $i => [$x, $y]) {
                    $couples[] = [hypot($x - $cible[0], ($y - $cible[1]) * 1.6), $p->getJoueur()->getId(), $i];
                }
            }
        }
        usort($couples, fn (array $a, array $b) => $a[0] <=> $b[0]);

        $attribuees = $prises = [];
        foreach ($couples as [, $joueur, $i]) {
            if (!isset($attribuees[$joueur]) && !isset($prises[$i])) {
                $attribuees[$joueur] = $places[$i];
                $prises[$i] = true;
            }
        }
        $libres = array_values(array_diff_key($places, $prises));
        foreach ($titulaires as $p) {
            $attribuees[$p->getJoueur()->getId()] ??= array_shift($libres) ?? [50.0, 50.0];
        }

        return $attribuees;
    }
}
