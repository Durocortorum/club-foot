<?php

namespace App\Service;

use App\Entity\Rencontre;

/**
 * Bilan complet d'une équipe sur les matchs joués : forme, domicile / extérieur, compétitions,
 * repères (moyennes, records, séries) et comportement selon le score à la mi-temps.
 */
class BilanEquipe
{
    /** Points d'un résultat, comme en championnat. */
    private const POINTS = ['V' => 3, 'N' => 1, 'D' => 0];

    /**
     * @param list<Rencontre> $rencontres matchs filtrés (joués ou non), dans n'importe quel ordre
     *
     * @return array<string, mixed>|null null s'il n'y a aucun match joué
     */
    public function analyser(array $rencontres): ?array
    {
        $joues = array_values(array_filter($rencontres, fn (Rencontre $r) => null !== $r->getResultat()));
        if (!$joues) {
            return null;
        }
        usort($joues, fn (Rencontre $a, Rencontre $b) => [$a->getDate(), $a->getId()] <=> [$b->getDate(), $b->getId()]);

        $lieux = ['Domicile' => [], 'Extérieur' => []];
        $competitions = [];
        foreach ($joues as $r) {
            $lieux[$r->isADomicile() ? 'Domicile' : 'Extérieur'][] = $r;
            $competitions[$r->getCompetition() ?: 'Non précisée'][] = $r;
        }
        ksort($competitions);

        return [
            'forme' => \array_slice($joues, -5),
            'serie' => $this->serieEnCours($joues),
            'lieux' => ['Total' => $this->ligne($joues)] + array_map(fn (array $l) => $this->ligne($l), $lieux),
            'competitions' => \count($competitions) > 1 ? array_map(fn (array $l) => $this->ligne($l), $competitions) : [],
            'reperes' => $this->reperes($joues),
            'mi_temps' => $this->miTemps($joues),
        ];
    }

    /** @param list<Rencontre> $matchs */
    private function ligne(array $matchs): array
    {
        $l = ['J' => \count($matchs), 'V' => 0, 'N' => 0, 'D' => 0, 'pour' => 0, 'contre' => 0, 'points' => 0];
        foreach ($matchs as $r) {
            ++$l[$r->getResultat()];
            $l['pour'] += $r->getButsPour();
            $l['contre'] += $r->getButsContre();
            $l['points'] += self::POINTS[$r->getResultat()];
        }
        $l['diff'] = $l['pour'] - $l['contre'];
        $l['victoires_pct'] = $l['J'] ? (int) round(100 * $l['V'] / $l['J']) : 0;
        $l['points_match'] = $l['J'] ? round($l['points'] / $l['J'], 2) : 0;

        return $l;
    }

    /**
     * Série en cours, du match le plus récent vers les plus anciens : « 3 victoires de suite »,
     * sinon « 4 matchs sans défaite » ou « 2 matchs sans victoire ».
     *
     * @param list<Rencontre> $joues
     */
    private function serieEnCours(array $joues): ?string
    {
        $resultats = array_reverse(array_map(fn (Rencontre $r) => $r->getResultat(), $joues));
        $compter = function (callable $condition) use ($resultats): int {
            $n = 0;
            foreach ($resultats as $res) {
                if (!$condition($res)) {
                    break;
                }
                ++$n;
            }

            return $n;
        };
        $noms = ['V' => ['victoire', 'victoires'], 'N' => ['nul', 'nuls'], 'D' => ['défaite', 'défaites']];
        $memes = $compter(fn (string $res) => $res === $resultats[0]);
        if ($memes >= 2) {
            return $memes . ' ' . $noms[$resultats[0]][1] . ' de suite';
        }
        $sansDefaite = $compter(fn (string $res) => 'D' !== $res);
        if ($sansDefaite >= 2) {
            return $sansDefaite . ' matchs sans défaite';
        }
        $sansVictoire = $compter(fn (string $res) => 'V' !== $res);

        return $sansVictoire >= 2 ? $sansVictoire . ' matchs sans victoire' : null;
    }

    /** @param list<Rencontre> $joues */
    private function reperes(array $joues): array
    {
        $n = \count($joues);
        $pour = array_sum(array_map(fn (Rencontre $r) => $r->getButsPour(), $joues));
        $contre = array_sum(array_map(fn (Rencontre $r) => $r->getButsContre(), $joues));
        $victoires = array_filter($joues, fn (Rencontre $r) => 'V' === $r->getResultat());
        $defaites = array_filter($joues, fn (Rencontre $r) => 'D' === $r->getResultat());
        // Écart le plus grand, puis le plus de buts marqués (victoire) ou encaissés (défaite).
        $ecart = fn (Rencontre $r) => abs($r->getButsPour() - $r->getButsContre());
        usort($victoires, fn (Rencontre $a, Rencontre $b) => [$ecart($b), $b->getButsPour()] <=> [$ecart($a), $a->getButsPour()]);
        usort($defaites, fn (Rencontre $a, Rencontre $b) => [$ecart($b), $b->getButsContre()] <=> [$ecart($a), $a->getButsContre()]);

        return [
            'pour_match' => round($pour / $n, 1),
            'contre_match' => round($contre / $n, 1),
            'sans_encaisser' => \count(array_filter($joues, fn (Rencontre $r) => 0 === $r->getButsContre())),
            'sans_marquer' => \count(array_filter($joues, fn (Rencontre $r) => 0 === $r->getButsPour())),
            'plus_large_victoire' => $victoires[0] ?? null,
            'plus_lourde_defaite' => $defaites[0] ?? null,
            'serie_victoires' => $this->plusLongueSerie($joues, fn (string $res) => 'V' === $res),
            'serie_sans_defaite' => $this->plusLongueSerie($joues, fn (string $res) => 'D' !== $res),
        ];
    }

    /** @param list<Rencontre> $joues */
    private function plusLongueSerie(array $joues, callable $condition): int
    {
        $meilleure = $courante = 0;
        foreach ($joues as $r) {
            $courante = $condition($r->getResultat()) ? $courante + 1 : 0;
            $meilleure = max($meilleure, $courante);
        }

        return $meilleure;
    }

    /**
     * Résultat final selon le score à la pause (matchs dont le score de la mi-temps est saisi).
     *
     * @param list<Rencontre> $joues
     *
     * @return array{matchs: int, situations: array<string, array{V: int, N: int, D: int}>}
     */
    private function miTemps(array $joues): array
    {
        $situations = ['En tête à la pause' => ['V' => 0, 'N' => 0, 'D' => 0], 'À égalité à la pause' => ['V' => 0, 'N' => 0, 'D' => 0], 'Mené à la pause' => ['V' => 0, 'N' => 0, 'D' => 0]];
        $n = 0;
        foreach ($joues as $r) {
            [$d, $e] = [$r->getButsDomicileMiTemps(), $r->getButsExterieurMiTemps()];
            if (null === $d || null === $e) {
                continue;
            }
            [$pour, $contre] = $r->isADomicile() ? [$d, $e] : [$e, $d];
            $cle = $pour > $contre ? 'En tête à la pause' : ($pour < $contre ? 'Mené à la pause' : 'À égalité à la pause');
            ++$situations[$cle][$r->getResultat()];
            ++$n;
        }

        return ['matchs' => $n, 'situations' => $situations];
    }
}
