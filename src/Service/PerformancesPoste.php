<?php

namespace App\Service;

use App\Entity\Joueur;
use App\Entity\Participation;

/**
 * Performances d'un joueur selon le poste occupé, sur une saison (page « Performances par poste » de sa fiche) :
 * matchs, minutes, note, résultats et actions notées en direct pour chaque poste, profil (radar), évolution de la
 * note mois par mois et quelques phrases de synthèse. Ne comptent que les matchs joués (minutes) avec un poste.
 */
class PerformancesPoste
{
    /** En dessous, une note moyenne ne dit pas grand-chose : pas de « meilleur poste » sur deux matchs. */
    public const MATCHS_MINI = 3;

    /** Axes du profil : pourcentages de réussite des actions notées en direct. */
    private const AXES = ['passe' => 'Passes', 'duel' => 'Duels', 'controle' => 'Contrôles', 'frappe' => 'Frappes cadrées', 'tete' => 'Têtes cadrées'];
    private const MOIS = [1 => 'Janv.', 'Févr.', 'Mars', 'Avril', 'Mai', 'Juin', 'Juil.', 'Août', 'Sept.', 'Oct.', 'Nov.', 'Déc.'];

    /** Dimensions des dessins (viewBox SVG). */
    private const RADAR = ['largeur' => 400, 'hauteur' => 270, 'rayon' => 92];
    private const COURBE = ['largeur' => 560, 'hauteur' => 210, 'gauche' => 34, 'droite' => 22, 'haut' => 30, 'bas' => 28];

    public function __construct(
        private readonly BilanJoueur $bilan,
        private readonly StatsActions $actions,
    ) {
    }

    /**
     * @param list<Participation> $participations du joueur (voir ParticipationRepository::parJoueur)
     */
    public function calculer(Joueur $joueur, array $participations, ?string $saison, ?string $poste): array
    {
        $jouees = array_values(array_filter($participations, fn (Participation $p) => $p->getTempsJeu() > 0
            && $p->getPoste() && 'Non utilisé' !== $p->getPoste() && !$p->getRencontre()->isPlateau() && $p->getRencontre()->getSaison()));
        $saisons = array_values(array_unique(array_map(fn (Participation $p) => $p->getRencontre()->getSaison(), $jouees)));
        rsort($saisons);
        $saison = \in_array($saison, $saisons, true) ? $saison : ($saisons[0] ?? null);
        $jouees = array_values(array_filter($jouees, fn (Participation $p) => $p->getRencontre()->getSaison() === $saison));
        if (!$jouees) {
            return ['saisons' => $saisons, 'saison' => $saison, 'postes' => [], 'poste' => null];
        }

        $buts = $this->bilan->butsParMatch($joueur);
        $actions = [];
        foreach ($this->actions->joueur($joueur, $jouees)['matchs'] as $m) {
            $actions[$m['rencontre']->getId()] = $m['a'];
        }

        $tous = self::vide('Tous les postes');
        $postes = [];
        foreach ($jouees as $p) {
            $nom = $p->getPoste();
            $postes[$nom] ??= self::vide($nom);
            $id = $p->getRencontre()->getId();
            $this->ajouter($postes[$nom], $p, $buts[$id] ?? null, $actions[$id] ?? []);
            $this->ajouter($tous, $p, $buts[$id] ?? null, $actions[$id] ?? []);
        }
        uasort($postes, fn ($a, $b) => [$b['matchs'], $b['minutes']] <=> [$a['matchs'], $a['minutes']]);
        $postes = array_map(fn ($l) => $this->terminer($l, $tous['minutes']), $postes);
        $tous = $this->terminer($tous, $tous['minutes']);

        $choisi = $postes[$poste] ?? reset($postes);
        $choisi['radar'] = $this->radar($choisi, $tous);
        $choisi['courbe'] = $this->courbe($choisi['mois']);
        $choisi['bref'] = $this->enBref($choisi, $tous, $postes);

        return ['saisons' => $saisons, 'saison' => $saison, 'postes' => $postes, 'tous' => $tous, 'poste' => $choisi];
    }

    private static function vide(string $nom): array
    {
        return ['nom' => $nom, 'matchs' => 0, 'titularisations' => 0, 'minutes' => 0, 'notes' => [], 'V' => 0, 'N' => 0, 'D' => 0, 'buts' => 0, 'passes' => 0, 'a' => [], 'mois' => []];
    }

    /** @param array{buts: int, passes: int}|null $buts */
    private function ajouter(array &$l, Participation $p, ?array $buts, array $actions): void
    {
        $r = $p->getRencontre();
        ++$l['matchs'];
        $l['titularisations'] += $p->isTitulaire() ? 1 : 0;
        $l['minutes'] += (int) $p->getTempsJeu();
        if ($resultat = $r->getResultat()) {
            ++$l[$resultat];
        }
        $l['buts'] += $buts['buts'] ?? 0;
        $l['passes'] += $buts['passes'] ?? 0;
        foreach ($actions as $code => $n) {
            $l['a'][$code] = ($l['a'][$code] ?? 0) + $n;
        }
        if (null !== $p->getNote()) {
            $l['notes'][] = $p->getNote();
            // Clé triable dans l'ordre de la saison (juillet → juin).
            $l['mois'][$r->getDate()->format('Y-m')][] = $p->getNote();
        }
    }

    private function terminer(array $l, int $minutesSaison): array
    {
        $joues = $l['V'] + $l['N'] + $l['D'];
        $l['note'] = $l['notes'] ? round(array_sum($l['notes']) / \count($l['notes']), 1) : null;
        $l['taux'] = $joues ? round(100 * $l['V'] / $joues, 1) : null;
        $l['avecResultat'] = $joues;
        $l['part'] = $minutesSaison ? (int) round(100 * $l['minutes'] / $minutesSaison) : 0;
        ksort($l['mois']);

        return $l;
    }

    /**
     * Profil du poste, comparé à l'ensemble de ses postes : réussite des actions notées en direct (axes ayant des
     * actions à ce poste ; pas de radar sous trois axes).
     */
    private function radar(array $poste, array $tous): ?array
    {
        $axes = [];
        foreach (self::AXES as $groupe => $libelle) {
            if (null !== ($v = MatchDirect::reussite($poste['a'], $groupe))) {
                $axes[] = ['libelle' => $libelle, 'valeur' => $v, 'tous' => MatchDirect::reussite($tous['a'], $groupe) ?? 0];
            }
        }
        if (\count($axes) < 3) {
            return null;
        }

        ['largeur' => $w, 'hauteur' => $h, 'rayon' => $r] = self::RADAR;
        [$cx, $cy] = [$w / 2, $h / 2 + 4];
        $n = \count($axes);
        $point = function (int $i, float $pct, float $marge = 0) use ($cx, $cy, $r, $n): array {
            $angle = -M_PI / 2 + 2 * M_PI * $i / $n;

            return [round($cx + cos($angle) * ($r * $pct / 100 + $marge), 1), round($cy + sin($angle) * ($r * $pct / 100 + $marge), 1)];
        };
        $trace = fn (callable $valeur) => implode(' ', array_map(fn ($i) => implode(',', $point($i, $valeur($i))), range(0, $n - 1)));

        foreach ($axes as $i => &$a) {
            [$a['x'], $a['y']] = $point($i, 100);
            [$a['lx'], $a['ly']] = $point($i, 100, 16);
            [$a['px'], $a['py']] = $point($i, $a['valeur']);
            $a['ancre'] = abs($a['lx'] - $cx) < 8 ? 'middle' : ($a['lx'] > $cx ? 'start' : 'end');
        }
        unset($a);

        return [
            'largeur' => $w, 'hauteur' => $h, 'cx' => $cx, 'cy' => $cy,
            'axes' => $axes,
            'grilles' => array_map(fn ($pct) => $trace(fn () => $pct), [25, 50, 75, 100]),
            'poste' => $trace(fn ($i) => $axes[$i]['valeur']),
            'tous' => $trace(fn ($i) => $axes[$i]['tous']),
        ];
    }

    /** Courbe de la note moyenne, mois par mois (au moins deux mois notés). */
    private function courbe(array $mois): ?array
    {
        if (\count($mois) < 2) {
            return null;
        }
        $points = [];
        foreach ($mois as $cle => $notes) {
            $points[] = ['mois' => self::MOIS[(int) substr($cle, 5)], 'note' => round(array_sum($notes) / \count($notes), 1), 'matchs' => \count($notes)];
        }
        $notes = array_column($points, 'note');
        // Échelle : un demi-point de marge, au moins deux points d'écart, graduée par 0,5 (ou 1 au-delà de 3 points).
        $min = max(0, floor((min($notes) - .5) * 2) / 2);
        $max = min(10, ceil((max($notes) + .5) * 2) / 2);
        if ($max - $min < 2) {
            $max = min(10, $min + 2);
            $min = $max - 2;
        }
        $pas = $max - $min > 3 ? 1 : .5;

        ['largeur' => $w, 'hauteur' => $h, 'gauche' => $g, 'droite' => $d, 'haut' => $ht, 'bas' => $b] = self::COURBE;
        $y = fn (float $v) => round($h - $b - ($v - $min) / ($max - $min) * ($h - $b - $ht), 1);
        $ecart = ($w - $g - $d) / (\count($points) - 1);
        foreach ($points as $i => &$p) {
            $p['x'] = round($g + $i * $ecart, 1);
            $p['y'] = $y($p['note']);
        }
        unset($p);
        $graduations = [];
        for ($v = $min; $v <= $max + .001; $v += $pas) {
            $graduations[] = ['valeur' => $v, 'y' => $y($v)];
        }
        $trace = implode(' ', array_map(fn ($p) => $p['x'] . ',' . $p['y'], $points));
        $bas = $h - $b;

        return [
            'largeur' => $w, 'hauteur' => $h, 'gauche' => $g, 'droite' => $w - $d,
            'points' => $points, 'graduations' => $graduations, 'trace' => $trace,
            'aire' => $points[0]['x'] . ',' . $bas . ' ' . $trace . ' ' . end($points)['x'] . ',' . $bas,
        ];
    }

    /** Phrases de synthèse du poste choisi. */
    private function enBref(array $p, array $tous, array $postes): array
    {
        $f = fn (?float $v) => number_format((float) $v, 1, ',', ' ');
        $phrases = [];

        $comparables = array_filter($postes, fn ($l) => $l['matchs'] >= self::MATCHS_MINI && null !== $l['note']);
        if (\count($comparables) >= 2) {
            uasort($comparables, fn ($a, $b) => $b['note'] <=> $a['note']);
            $meilleur = reset($comparables);
            $phrases[] = $meilleur['nom'] === $p['nom']
                ? 'Son poste le mieux noté cette saison'
                : 'Mieux noté au poste de ' . mb_strtolower($meilleur['nom']) . ' (' . $f($meilleur['note']) . ')';
        }
        if (null !== $p['note'] && \count($postes) > 1 && null !== $tous['note']) {
            $ecart = $p['note'] - $tous['note'];
            $phrases[] = 'Note de ' . $f($p['note']) . ' /10, ' . match (true) {
                $ecart >= .2 => 'au-dessus de',
                $ecart <= -.2 => 'en dessous de',
                default => 'dans',
            } . ' sa moyenne tous postes (' . $f($tous['note']) . ')';
        } elseif (null !== $p['note']) {
            $phrases[] = 'Note moyenne de ' . $f($p['note']) . ' /10';
        }
        if ($p['avecResultat']) {
            $phrases[] = $p['V'] . ' ' . ($p['V'] > 1 ? 'victoires' : 'victoire') . ', ' . $p['N'] . ' ' . ($p['N'] > 1 ? 'nuls' : 'nul') . ', ' . $p['D'] . ' ' . ($p['D'] > 1 ? 'défaites' : 'défaite') . ' à ce poste';
        }
        if (\count($postes) > 1) {
            $phrases[] = $p['part'] . ' % de son temps de jeu de la saison';
        }
        if ($c = $p['courbe']) {
            $premier = $c['points'][0];
            $dernier = end($c['points']);
            $ecart = $dernier['note'] - $premier['note'];
            $phrases[] = match (true) {
                $ecart >= .3 => 'Note en hausse depuis ' . mb_strtolower($premier['mois']) . ' (+' . $f($ecart) . ')',
                $ecart <= -.3 => 'Note en baisse depuis ' . mb_strtolower($premier['mois']) . ' (−' . $f(-$ecart) . ')',
                default => 'Note régulière au fil des mois',
            };
        }
        if ($p['buts'] || $p['passes']) {
            $phrases[] = implode(' et ', array_filter([
                $p['buts'] ? $p['buts'] . ' ' . ($p['buts'] > 1 ? 'buts' : 'but') : null,
                $p['passes'] ? $p['passes'] . ' ' . ($p['passes'] > 1 ? 'passes décisives' : 'passe décisive') : null,
            ])) . ' à ce poste';
        }

        return $phrases;
    }
}
