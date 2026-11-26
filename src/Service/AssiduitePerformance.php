<?php

namespace App\Service;

use App\Entity\Participation;
use App\Entity\Presence;
use App\Entity\Rencontre;
use App\Entity\ResultatTest;
use App\Entity\SemainePresence;

/**
 * Assiduité et performance d'un joueur sur une saison : présence aux entraînements (séance par séance, mois par mois),
 * temps de jeu et notes de match, résultats des tests, et la tendance entre la présence des quatre semaines qui
 * précèdent un match et la note obtenue. Ce sont des tendances observées, pas une relation de cause à effet.
 */
class AssiduitePerformance
{
    private const JOURS = ['lun' => 0, 'mar' => 1, 'mer' => 2, 'jeu' => 3, 'ven' => 4, 'sam' => 5];
    private const MOIS = ['', 'Janv.', 'Févr.', 'Mars', 'Avr.', 'Mai', 'Juin', 'Juil.', 'Août', 'Sept.', 'Oct.', 'Nov.', 'Déc.'];
    private const MOIS_LONGS = ['', 'Janvier', 'Février', 'Mars', 'Avril', 'Mai', 'Juin', 'Juillet', 'Août', 'Septembre', 'Octobre', 'Novembre', 'Décembre'];
    /** Graphiques (unités du dessin SVG). */
    public const LARGEUR = 600;
    public const HAUTEUR = 240;
    private const MARGE_G = 42;
    private const MARGE_D = 42;
    private const MARGE_H = 14;
    private const MARGE_B = 30;
    /** Présence prise en compte avant chaque match (la note du match y est comparée). */
    public const JOURS_AVANT_MATCH = 28;
    public const MATCHS_MINI = 5;

    /**
     * @param list<Presence>      $presences toutes les semaines du joueur
     * @param list<Participation> $matchs    ses feuilles de match
     * @param list<ResultatTest>  $tests     ses résultats de tests (vide si non autorisé)
     *
     * @return array<string, mixed>
     */
    public function calculer(array $presences, array $matchs, array $tests, ?string $saison = null): array
    {
        $seancesToutes = $this->seances($presences);
        $saisons = array_unique([
            ...array_map(fn (array $s) => Rencontre::saisonDe($s['date']), $seancesToutes),
            ...array_map(fn (Participation $p) => Rencontre::saisonDe($p->getRencontre()->getDate()), array_filter($matchs, fn (Participation $p) => $p->getRencontre()?->getDate())),
        ]);
        rsort($saisons);
        $actuelle = Rencontre::saisonDe(new \DateTimeImmutable('today'));
        $saison = \in_array($saison, $saisons, true) ? $saison : (\in_array($actuelle, $saisons, true) ? $actuelle : ($saisons[0] ?? $actuelle));
        [$debut, $fin] = Rencontre::bornesSaison($saison);
        $dansSaison = fn (\DateTimeInterface $d) => $d >= $debut && $d <= $fin;

        $seances = array_values(array_filter($seancesToutes, fn (array $s) => $dansSaison($s['date'])));
        $joues = array_values(array_filter($matchs, fn (Participation $p) => $p->getRencontre()?->getDate() && $dansSaison($p->getRencontre()->getDate())));
        usort($joues, fn (Participation $a, Participation $b) => $a->getRencontre()->getDate() <=> $b->getRencontre()->getDate());
        $presents = \count(array_filter($seances, fn (array $s) => $s['present']));
        $notes = array_values(array_filter(array_map(fn (Participation $p) => $p->getNote(), $joues), fn ($n) => null !== $n));
        $aJoue = array_filter($joues, fn (Participation $p) => ($p->getTempsJeu() ?? 0) > 0 || $p->isTitulaire());

        $testsSaison = $this->tests(array_values(array_filter($tests, fn (ResultatTest $r) => $dansSaison($r->getDate()))));
        $progres = array_values(array_filter(array_column($testsSaison, 'progres'), fn ($v) => null !== $v));

        return [
            'saison' => $saison,
            'saisons' => $saisons,
            'seances' => \count($seances),
            'presents' => $presents,
            'taux' => $seances ? (int) round(100 * $presents / \count($seances)) : null,
            'matchs' => \count($aJoue),
            'minutes' => array_sum(array_map(fn (Participation $p) => $p->getTempsJeu() ?? 0, $joues)),
            'note' => $notes ? round(array_sum($notes) / \count($notes), 1) : null,
            'notes' => \count($notes),
            'tests' => $testsSaison,
            'progres_tests' => $progres ? round(array_sum($progres) / \count($progres), 1) : null,
            'evolution' => $this->evolution($seances, $joues),
            'calendrier' => $this->calendrier($seances),
            'relation' => $this->relation($seancesToutes, $joues),
        ];
    }

    /**
     * Chaque demi-journée notée : date, présent ou non, motif d'absence.
     *
     * @param list<Presence> $presences
     *
     * @return list<array{date: \DateTimeImmutable, present: bool, motif: ?string}>
     */
    private function seances(array $presences): array
    {
        $seances = [];
        foreach ($presences as $p) {
            $lundi = $p->getSemaine()?->getLundi();
            if (!$lundi) {
                continue;
            }
            foreach ($p->getCreneaux() as $creneau => $valeur) {
                $jour = self::JOURS[substr($creneau, 0, 3)] ?? 0;
                $absent = SemainePresence::estAbsence($valeur);
                $seances[] = ['date' => $lundi->modify("+$jour days"), 'present' => !$absent, 'motif' => $absent ? (SemainePresence::tousMotifs()[$valeur] ?? $valeur) : null];
            }
        }
        usort($seances, fn (array $a, array $b) => $a['date'] <=> $b['date']);

        return $seances;
    }

    /**
     * Mois par mois : taux de présence et note moyenne des matchs, avec leurs places sur le graphique (0 à 100 %,
     * la note sur 10 multipliée par 10 sur la même échelle).
     *
     * @param list<array{date: \DateTimeImmutable, present: bool}> $seances
     * @param list<Participation>                                   $matchs
     *
     * @return array{mois: list<array<string, mixed>>, trace_presence: string, trace_note: string}|null
     */
    private function evolution(array $seances, array $matchs): ?array
    {
        $parMois = [];
        foreach ($seances as $s) {
            $cle = $s['date']->format('Y-m');
            $parMois[$cle]['seances'] = ($parMois[$cle]['seances'] ?? 0) + 1;
            $parMois[$cle]['presents'] = ($parMois[$cle]['presents'] ?? 0) + ($s['present'] ? 1 : 0);
        }
        foreach ($matchs as $p) {
            if (null !== $p->getNote()) {
                $parMois[$p->getRencontre()->getDate()->format('Y-m')]['notes'][] = $p->getNote();
            }
        }
        if (!$parMois) {
            return null;
        }
        // Du premier au dernier mois avec des données (trous compris, pour garder l'échelle du temps).
        $cles = array_keys($parMois);
        sort($cles);
        $mois = [];
        for ($m = new \DateTimeImmutable($cles[0] . '-01'); $m->format('Y-m') <= end($cles); $m = $m->modify('+1 month')) {
            $d = $parMois[$m->format('Y-m')] ?? [];
            $mois[] = [
                'libelle' => self::MOIS[(int) $m->format('n')],
                'taux' => !empty($d['seances']) ? (int) round(100 * $d['presents'] / $d['seances']) : null,
                'note' => !empty($d['notes']) ? round(array_sum($d['notes']) / \count($d['notes']), 1) : null,
            ];
        }
        $n = \count($mois);
        $largeur = self::LARGEUR - self::MARGE_G - self::MARGE_D;
        $hauteur = self::HAUTEUR - self::MARGE_H - self::MARGE_B;
        // Échelle : de 50 % (ou plus bas si besoin) à 100 %.
        $valeurs = array_merge(array_filter(array_column($mois, 'taux'), fn ($v) => null !== $v), array_map(fn ($v) => $v * 10, array_filter(array_column($mois, 'note'), fn ($v) => null !== $v)));
        $bas = max(0, min(50, (int) floor((min($valeurs ?: [50]) - 5) / 10) * 10));
        $y = fn (float $v) => round(self::MARGE_H + (1 - ($v - $bas) / (100 - $bas)) * $hauteur, 1);
        foreach ($mois as $i => &$m) {
            $m['x'] = round(self::MARGE_G + ($n > 1 ? $i / ($n - 1) * $largeur : $largeur / 2), 1);
            $m['y_taux'] = null !== $m['taux'] ? $y($m['taux']) : null;
            $m['y_note'] = null !== $m['note'] ? $y($m['note'] * 10) : null;
        }
        unset($m);
        $graduations = [];
        for ($v = $bas; $v <= 100; $v += ($bas <= 30 ? 20 : 10)) {
            $graduations[] = ['valeur' => $v, 'y' => $y($v)];
        }
        $trace = fn (string $cle) => implode(' ', array_map(fn ($m) => $m['x'] . ',' . $m[$cle], array_filter($mois, fn ($m) => null !== $m[$cle])));

        return ['mois' => $mois, 'graduations' => $graduations, 'trace_presence' => $trace('y_taux'), 'trace_note' => $trace('y_note'),
            'bas' => self::MARGE_H + $hauteur, 'gauche' => self::MARGE_G, 'droite' => self::LARGEUR - self::MARGE_D];
    }

    /**
     * Calendrier des présences mois par mois (le plus récent d'abord) : chaque jour présent, absent (motif) ou sans séance.
     *
     * @param list<array{date: \DateTimeImmutable, present: bool, motif: ?string}> $seances
     *
     * @return list<array{titre: string, vides: int, jours: list<array{jour: int, etat: ?string, motif: ?string}>, seances: int, presents: int, taux: ?int}>
     */
    private function calendrier(array $seances): array
    {
        $parJour = [];
        foreach ($seances as $s) {
            $cle = $s['date']->format('Y-m-d');
            // Deux séances le même jour : présent si présent à l'une, sinon le premier motif.
            if (!isset($parJour[$cle]) || $s['present']) {
                $parJour[$cle] = $s;
            }
        }
        $mois = [];
        foreach ($seances as $s) {
            $mois[$s['date']->format('Y-m')] = true;
        }
        $calendrier = [];
        foreach (array_reverse(array_keys($mois)) as $cle) {
            $premier = new \DateTimeImmutable($cle . '-01');
            $jours = [];
            for ($j = 1; $j <= (int) $premier->format('t'); ++$j) {
                $s = $parJour[$premier->format('Y-m-') . sprintf('%02d', $j)] ?? null;
                $jours[] = ['jour' => $j, 'etat' => $s ? ($s['present'] ? 'present' : 'absent') : null, 'motif' => $s['motif'] ?? null];
            }
            $duMois = array_filter($seances, fn (array $s) => $s['date']->format('Y-m') === $cle);
            $presents = \count(array_filter($duMois, fn (array $s) => $s['present']));
            $calendrier[] = [
                'titre' => self::MOIS_LONGS[(int) $premier->format('n')] . ' ' . $premier->format('Y'),
                'vides' => (int) $premier->format('N') - 1,
                'jours' => $jours,
                'seances' => \count($duMois),
                'presents' => $presents,
                'taux' => $duMois ? (int) round(100 * $presents / \count($duMois)) : null,
            ];
        }

        return $calendrier;
    }

    /**
     * Pour chaque match noté : présence aux séances des quatre semaines d'avant (au moins deux séances) et note du
     * match ; droite de tendance et coefficient de corrélation (de -1 à 1), lu en mots.
     *
     * @param list<array{date: \DateTimeImmutable, present: bool}> $seances toutes les séances (le début de saison
     *                                                                       regarde aussi la fin de la précédente)
     * @param list<Participation>                                   $matchs  matchs de la saison
     *
     * @return array<string, mixed>
     */
    private function relation(array $seances, array $matchs): array
    {
        $points = [];
        foreach ($matchs as $p) {
            if (null === $p->getNote()) {
                continue;
            }
            $date = \DateTimeImmutable::createFromInterface($p->getRencontre()->getDate());
            $avant = array_filter($seances, fn (array $s) => $s['date'] < $date && $s['date'] >= $date->modify('-' . self::JOURS_AVANT_MATCH . ' days'));
            if (\count($avant) < 2) {
                continue;
            }
            $points[] = [
                'x' => round(100 * \count(array_filter($avant, fn (array $s) => $s['present'])) / \count($avant), 1),
                'y' => (float) $p->getNote(),
                'match' => $p->getRencontre()->getDate()->format('d/m') . ' · ' . $p->getRencontre()->getAdversaire(),
            ];
        }
        $n = \count($points);
        if ($n < self::MATCHS_MINI) {
            return ['points' => [], 'n' => $n, 'r' => null];
        }
        $mx = array_sum(array_column($points, 'x')) / $n;
        $my = array_sum(array_column($points, 'y')) / $n;
        $sxy = $sxx = $syy = 0.0;
        foreach ($points as $p) {
            $sxy += ($p['x'] - $mx) * ($p['y'] - $my);
            $sxx += ($p['x'] - $mx) ** 2;
            $syy += ($p['y'] - $my) ** 2;
        }
        $r = $sxx > 0 && $syy > 0 ? $sxy / sqrt($sxx * $syy) : null;
        $pente = $sxx > 0 ? $sxy / $sxx : 0.0;

        // Axes : présence d'une dizaine sous le plus bas taux observé jusqu'à 100 %, note autour des valeurs observées.
        $xMin = min(array_column($points, 'x'));
        $xMax = max(array_column($points, 'x'));
        $x0 = max(0, min(80, (int) floor($xMin / 10) * 10 - 10));
        $y0 = max(0, floor(min(array_column($points, 'y'))) - 1);
        $y1 = min(10, ceil(max(array_column($points, 'y'))) + 1);
        $largeur = self::LARGEUR - self::MARGE_G - self::MARGE_D;
        $hauteur = self::HAUTEUR - self::MARGE_H - self::MARGE_B;
        $px = fn (float $x) => round(self::MARGE_G + ($x - $x0) / (100 - $x0) * $largeur, 1);
        $py = fn (float $y) => round(self::MARGE_H + (1 - ($y - $y0) / max(1, $y1 - $y0)) * $hauteur, 1);
        foreach ($points as &$p) {
            $p['px'] = $px($p['x']);
            $p['py'] = $py($p['y']);
        }
        unset($p);
        $ligne = fn (float $x) => max($y0, min($y1, $my + $pente * ($x - $mx)));
        $axeX = [];
        for ($v = $x0; $v <= 100; $v += 10) {
            $axeX[] = ['valeur' => $v, 'x' => $px($v)];
        }
        $axeY = [];
        for ($v = $y0; $v <= $y1; ++$v) {
            $axeY[] = ['valeur' => $v, 'y' => $py($v)];
        }

        return [
            'points' => $points,
            'n' => $n,
            'r' => null !== $r ? round($r, 2) : null,
            'lecture' => $this->lecture($r),
            // Tendance tracée seulement sur l'étendue observée (pas de prolongement au-delà des matchs).
            'droite' => ['x1' => $px($xMin), 'y1' => $py($ligne($xMin)), 'x2' => $px($xMax), 'y2' => $py($ligne($xMax))],
            'axe_x' => $axeX,
            'axe_y' => $axeY,
            'bas' => self::MARGE_H + $hauteur,
            'gauche' => self::MARGE_G,
            'droite_x' => self::LARGEUR - self::MARGE_D,
        ];
    }

    /** Coefficient de corrélation lu en mots (force et sens de la tendance). */
    private function lecture(?float $r): array
    {
        if (null === $r || abs($r) < 0.2) {
            return ['force' => 'Pas de tendance nette', 'classe' => 'neutre', 'texte' => 'Ses notes de match ne suivent pas vraiment sa présence à l\'entraînement sur cette saison.'];
        }
        $force = abs($r) < 0.4 ? 'Tendance faible' : (abs($r) < 0.6 ? 'Tendance modérée' : 'Tendance forte');

        return $r > 0
            ? ['force' => $force, 'classe' => 'positive', 'texte' => 'Quand il est plus présent à l\'entraînement les semaines d\'avant, ses notes de match ont tendance à être meilleures.']
            : ['force' => $force, 'classe' => 'negative', 'texte' => 'Ses notes de match ont plutôt tendance à baisser quand il est plus présent : à regarder avec le contexte (charge, fatigue, adversaires).'];
    }

    /**
     * Résultats des tests de la saison : premier et dernier résultat de chaque test, progrès en % (positif = mieux,
     * même pour un test où le plus bas est le meilleur, comme un sprint).
     *
     * @param list<ResultatTest> $resultats
     *
     * @return list<array{nom: string, unite: ?string, famille: string, dernier: float, premier: float, progres: ?float, nb: int}>
     */
    private function tests(array $resultats): array
    {
        $parTest = [];
        foreach ($resultats as $r) {
            $parTest[$r->getTest()->getId()][] = $r;
        }
        $lignes = [];
        foreach ($parTest as $liste) {
            usort($liste, fn (ResultatTest $a, ResultatTest $b) => $a->getDate() <=> $b->getDate());
            $premier = $liste[0];
            $dernier = end($liste);
            $test = $dernier->getTest();
            $progres = \count($liste) > 1 && $premier->getValeur()
                ? ($dernier->getValeur() - $premier->getValeur()) / abs($premier->getValeur()) * 100 * ($test->isPlusBasMieux() ? -1 : 1) : null;
            $lignes[] = [
                'nom' => (string) $test->getNom(), 'unite' => $test->getUnite(), 'famille' => $test->getFamille(), 'ordre' => $test->getOrdre(),
                'premier' => $premier->getValeur(), 'dernier' => $dernier->getValeur(),
                'progres' => null !== $progres ? round($progres, 1) : null, 'nb' => \count($liste),
            ];
        }
        usort($lignes, fn ($a, $b) => [$a['ordre'], $a['nom']] <=> [$b['ordre'], $b['nom']]);

        return $lignes;
    }
}
