<?php

namespace App\Service;

use App\Entity\ActionMatch;
use App\Entity\Joueur;
use App\Entity\Participation;
use App\Entity\Rencontre;
use App\Repository\ActionMatchRepository;

/**
 * Actions notées en direct (passes, duels, contrôles, frappes, têtes, centres…) additionnées sur plusieurs matchs :
 * saison d'une équipe (page Statistiques, onglet « Actions ») ou parcours d'un joueur (sa fiche).
 * Seuls comptent les matchs « suivis » : au moins une action notée en direct ; chacun est complété par sa feuille
 * de match, comme son bilan (MatchDirect::avecFeuille).
 */
class StatsActions
{
    /** En dessous, une moyenne « par 90 minutes » n'a pas de sens (un remplaçant entré 5 minutes). */
    public const MINUTES_MINI = 90;

    public function __construct(
        private readonly ActionMatchRepository $actions,
        private readonly MatchDirect $direct,
    ) {
    }

    /** Compteurs à zéro : nos actions, celles de l'adversaire, passes décisives. */
    public static function vide(): array
    {
        return array_fill_keys(array_merge(array_keys(ActionMatch::ACTIONS), array_keys(ActionMatch::ADVERSES), ['PD']), 0);
    }

    /**
     * Statistiques de chaque match suivi en direct.
     *
     * @param list<Rencontre> $rencontres
     *
     * @return list<array{rencontre: Rencontre, stats: array, minutes: array<int, int>}> minutes jouées par identifiant de joueur (feuille de match)
     */
    public function parMatch(array $rencontres): array
    {
        $notees = [];
        foreach ($this->actions->desMatchs($rencontres) as $a) {
            $notees[$a->getRencontre()->getId()][] = $a;
        }
        $resultat = [];
        foreach ($rencontres as $r) {
            if (!isset($notees[$r->getId()])) {
                continue;
            }
            $minutes = [];
            foreach ($r->getParticipations() as $p) {
                if ($p->getJoueur()) {
                    $minutes[$p->getJoueur()->getId()] = (int) $p->getTempsJeu();
                }
            }
            $resultat[] = ['rencontre' => $r, 'stats' => $this->direct->statistiques($r, $this->direct->avecFeuille($r, $notees[$r->getId()])), 'minutes' => $minutes];
        }

        return $resultat;
    }

    /**
     * Saison d'une équipe (ou de plusieurs) : totaux de l'équipe, ligne de chaque joueur, quarts d'heure.
     *
     * @param list<Rencontre> $rencontres
     *
     * @return array{matchs: int, equipe: array<string, int>, joueurs: list<array{id: int, nom: string, prenom: string, a: array<string, int>, total: int, matchs: int, minutes: int}>,
     *               quarts: list<array{debut: int, fin: int, actions: array<string, int>}>}
     */
    public function saison(array $rencontres): array
    {
        $matchs = $this->parMatch($rencontres);
        $equipe = self::vide();
        $joueurs = [];
        $quarts = [];
        $ligne = fn (Joueur $j) => ['id' => $j->getId(), 'nom' => (string) $j->getNom(), 'prenom' => (string) $j->getPrenom(), 'a' => [], 'total' => 0, 'matchs' => 0, 'minutes' => 0];

        foreach ($matchs as $m) {
            foreach ($m['stats']['equipe'] as $code => $n) {
                $equipe[$code] = ($equipe[$code] ?? 0) + $n;
            }
            // Quarts d'heure : additionnés par tranche (0-15, 15-30…), quelle que soit la durée du match.
            foreach ($m['stats']['quarts'] as $i => $q) {
                $quarts[$i] ??= ['debut' => $q['debut'], 'fin' => $q['fin'], 'actions' => []];
                $quarts[$i]['fin'] = max($quarts[$i]['fin'], $q['fin']);
                foreach ($q['actions'] as $code => $n) {
                    $quarts[$i]['actions'][$code] = ($quarts[$i]['actions'][$code] ?? 0) + $n;
                }
            }
            // Joueurs du match : ceux qui ont une action notée et ceux qui ont joué (feuille de match).
            $vus = [];
            foreach ($m['stats']['joueurs'] as $l) {
                $id = $l['joueur']->getId();
                $joueurs[$id] ??= $ligne($l['joueur']);
                foreach ($l['actions'] as $code => $n) {
                    $joueurs[$id]['a'][$code] = ($joueurs[$id]['a'][$code] ?? 0) + $n;
                }
                $joueurs[$id]['total'] += $l['total'];
                $vus[$id] = true;
            }
            foreach ($m['rencontre']->getParticipations() as $p) {
                if (($j = $p->getJoueur()) && $p->getTempsJeu() > 0) {
                    $joueurs[$j->getId()] ??= $ligne($j);
                    $vus[$j->getId()] = true;
                }
            }
            foreach (array_keys($vus) as $id) {
                ++$joueurs[$id]['matchs'];
                $joueurs[$id]['minutes'] += $m['minutes'][$id] ?? 0;
            }
        }
        ksort($quarts);

        return ['matchs' => \count($matchs), 'equipe' => $equipe, 'joueurs' => array_values($joueurs), 'quarts' => array_values($quarts)];
    }

    /**
     * Parcours d'un joueur dans les matchs suivis en direct : match par match et par saison, avec ce qu'a fait
     * l'équipe dans les mêmes matchs (pour comparer).
     *
     * @param list<Participation> $participations du joueur
     *
     * @return array{matchs: list<array{rencontre: Rencontre, a: array<string, int>, total: int, minutes: int, equipe: array<string, int>}>,
     *               saisons: array<string, array{matchs: int, minutes: int, a: array<string, int>, equipe: array<string, int>}>}
     */
    public function joueur(Joueur $joueur, array $participations): array
    {
        $rencontres = [];
        foreach ($participations as $p) {
            $rencontres[$p->getRencontre()->getId()] = $p->getRencontre();
        }
        foreach ($this->actions->rencontresDe($joueur) as $r) {
            $rencontres[$r->getId()] = $r;
        }

        $matchs = [];
        $saisons = [];
        foreach ($this->parMatch(array_values($rencontres)) as $m) {
            $a = [];
            $total = 0;
            foreach ($m['stats']['joueurs'] as $l) {
                if ($l['joueur']->getId() === $joueur->getId()) {
                    [$a, $total] = [$l['actions'], $l['total']];
                }
            }
            $minutes = $m['minutes'][$joueur->getId()] ?? 0;
            if (!$a && !$minutes) {
                continue; // sur le banc tout le match
            }
            $matchs[] = ['rencontre' => $m['rencontre'], 'a' => $a, 'total' => $total, 'minutes' => $minutes, 'equipe' => $m['stats']['equipe']];

            $saison = $m['rencontre']->getSaison() ?? '?';
            $saisons[$saison] ??= ['matchs' => 0, 'minutes' => 0, 'a' => [], 'equipe' => self::vide()];
            ++$saisons[$saison]['matchs'];
            $saisons[$saison]['minutes'] += $minutes;
            foreach ($a as $code => $n) {
                $saisons[$saison]['a'][$code] = ($saisons[$saison]['a'][$code] ?? 0) + $n;
            }
            foreach ($m['stats']['equipe'] as $code => $n) {
                $saisons[$saison]['equipe'][$code] = ($saisons[$saison]['equipe'][$code] ?? 0) + $n;
            }
        }
        usort($matchs, fn ($x, $y) => $y['rencontre']->getDate() <=> $x['rencontre']->getDate());
        krsort($saisons);

        return ['matchs' => $matchs, 'saisons' => $saisons];
    }

    /** Valeur servant à trier les joueurs de l'onglet « Actions ». */
    public static function valeur(array $l, string $tri): float
    {
        $a = $l['a'];
        $n = fn (string ...$codes) => array_sum(array_map(fn ($c) => $a[$c] ?? 0, $codes));

        return (float) match ($tri) {
            'passes' => $n('BP'),
            'pctPasses' => MatchDirect::reussite($a, 'passe') ?? -1,
            'duels' => $n('DG'),
            'pctDuels' => MatchDirect::reussite($a, 'duel') ?? -1,
            'controles' => $n('BC'),
            'pctControles' => MatchDirect::reussite($a, 'controle') ?? -1,
            'frappes' => $n('FC', 'FH', 'TC', 'TH'),
            'centres' => $n('C'),
            'buts' => $n('B'),
            'pd' => $n('PD'),
            'minutes' => $l['minutes'],
            default => $l['total'],
        };
    }
}
