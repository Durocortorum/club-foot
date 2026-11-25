<?php

namespace App\Service;

use App\Entity\ActionMatch;
use App\Entity\But;
use App\Entity\Convoque;
use App\Entity\Joueur;
use App\Entity\Participation;
use App\Entity\Rencontre;
use App\Repository\ConvocationRepository;
use App\Repository\JoueurRepository;

/**
 * Match en direct : joueurs proposés sur la tablette et statistiques des actions notées
 * (par joueur, par zone du terrain, par quart d'heure).
 */
class MatchDirect
{
    /** Pourcentages de réussite affichés : groupe => [code réussi, code raté]. */
    public const REUSSITES = ['passe' => ['BP', 'MP'], 'duel' => ['DG', 'DP'], 'controle' => ['BC', 'MC'], 'frappe' => ['FC', 'FH'], 'tete' => ['TC', 'TH']];

    public function __construct(
        private readonly ConvocationRepository $convocations,
        private readonly JoueurRepository $joueurs,
    ) {
    }

    /** Minute d'un but de la feuille, arrêts de jeu compris (« 45+2 » = 47). */
    private static function minuteDuBut(But $b): int
    {
        return (int) floor(Rencontre::ordreMinute($b->getTemps()) ?? 0) + (int) ($b->getAdditionnel() ?? 0);
    }

    /**
     * But noté en direct qui correspond à un but de la feuille : même camp, même buteur, minute à 5 près.
     * Retiré de la liste (chaque but du direct ne sert qu'une fois).
     *
     * @param list<ActionMatch> $buts
     */
    private static function butCorrespondant(array &$buts, string $type, ?Joueur $buteur, int $minute): ?ActionMatch
    {
        foreach ($buts as $i => $a) {
            if ($a->getType() === $type && $a->getJoueur()?->getId() === $buteur?->getId() && abs($a->getMinute() - $minute) <= 5) {
                unset($buts[$i]);

                return $a;
            }
        }

        return null;
    }

    /**
     * Joueurs du match : ceux de la feuille de match (avec leur numéro), sinon les convoqués qui n'ont pas dit « absent »,
     * sinon l'équipe ; les joueurs déjà notés pendant le match restent dans la liste.
     *
     * @param list<ActionMatch> $actions
     *
     * @return list<array{id: int, nom: string, prenom: string, numero: ?int}>
     */
    public function joueurs(Rencontre $rencontre, array $actions = []): array
    {
        $liste = [];
        foreach ($rencontre->getParticipations() as $p) {
            if ($p->getJoueur() && 'Non utilisé' !== $p->getPoste()) {
                $liste[$p->getJoueur()->getId()] = [$p->getJoueur(), $p->getNumero(), $p->getPoste()];
            }
        }
        if (!$liste && ($convocation = $this->convocations->pour($rencontre))) {
            foreach ($convocation->getConvoques() as $c) {
                if (Convoque::ABSENT !== $c->getReponse()) {
                    $liste[$c->getJoueur()->getId()] = [$c->getJoueur(), null];
                }
            }
        }
        if (!$liste && $rencontre->getCategorie()) {
            foreach ($this->joueurs->findBy(['categorie' => $rencontre->getCategorie(), 'ancien' => false]) as $j) {
                $liste[$j->getId()] = [$j, null];
            }
        }
        foreach ($actions as $a) {
            if ($a->getJoueur() && !isset($liste[$a->getJoueur()->getId()])) {
                $liste[$a->getJoueur()->getId()] = [$a->getJoueur(), null];
            }
        }
        // Numéros donnés sur la tablette, pour les joueurs qui n'en ont pas sur la feuille.
        foreach ($rencontre->getNumerosDirect() as $id => $numero) {
            if (isset($liste[$id]) && null === $liste[$id][1]) {
                $liste[$id][1] = $numero;
            }
        }
        uasort($liste, fn ($a, $b) => [$a[1] ?? 99, $a[0]->getNom(), $a[0]->getPrenom()] <=> [$b[1] ?? 99, $b[0]->getNom(), $b[0]->getPrenom()]);

        // Poste en abrégé (« AT ») : celui de la feuille de match, sinon le poste habituel du joueur.
        return array_values(array_map(fn (array $l) => ['id' => $l[0]->getId(), 'nom' => (string) $l[0]->getNom(), 'prenom' => (string) $l[0]->getPrenom(), 'numero' => $l[1],
            'poste' => Participation::abreviationPoste($l[2] ?? null) ?? Participation::abreviationPoste($l[0]->getPoste())], $liste));
    }

    /**
     * Numéro donné sur la tablette : sur la feuille de match si le joueur y est, sinon gardé avec le match
     * (repris quand la feuille sera préremplie). Null efface le numéro.
     */
    public function numeroter(Rencontre $rencontre, int $joueurId, ?int $numero): void
    {
        foreach ($rencontre->getParticipations() as $p) {
            if ($p->getJoueur()?->getId() === $joueurId) {
                $p->setNumero($numero);
                $rencontre->setNumeroDirect($joueurId, null);

                return;
            }
        }
        $rencontre->setNumeroDirect($joueurId, $numero);
    }

    /** Durée du match en minutes (catégorie), 90 par défaut. */
    public function duree(Rencontre $rencontre): int
    {
        return $rencontre->getCategorie()?->getDureeMatch() ?: 90;
    }

    /**
     * Statistiques des actions notées.
     *
     * @param list<ActionMatch> $actions
     *
     * @return array{joueurs: list<array{joueur: Joueur, actions: array<string, int>, total: int}>, equipe: array<string, int>,
     *               zones: array<string, array<int, array<int, int>>>, quarts: list<array{debut: int, fin: int, actions: array<string, int>}>, pour: int, contre: int}
     */
    public function statistiques(Rencontre $rencontre, array $actions): array
    {
        $parJoueur = [];
        $equipe = array_fill_keys(array_merge(array_keys(ActionMatch::ACTIONS), array_keys(ActionMatch::ADVERSES), ['PD']), 0);
        $vide = array_fill(0, ActionMatch::COLONNES, array_fill(0, ActionMatch::COULOIRS, 0));
        $zones = ['tout' => $vide];
        $duree = $this->duree($rencontre);
        $quarts = [];
        for ($debut = 0; $debut < $duree; $debut += 15) {
            $quarts[] = ['debut' => $debut, 'fin' => min($duree, $debut + 15), 'actions' => []];
        }
        foreach ($actions as $a) {
            $type = $a->getType();
            // Tirs au but : pas des buts ni des actions de jeu (règle FFF), seulement le résultat de la séance.
            if ($a->isTirAuBut()) {
                continue;
            }
            // Changement, exclusion temporaire : comptés pour l'équipe seulement (pas une action de jeu : ni zone, ni quart d'heure, ni joueur).
            if (isset(ActionMatch::HORS_JEU[$type])) {
                $equipe[$type] = ($equipe[$type] ?? 0) + 1;
                continue;
            }
            ++$equipe[$type];
            $zones[$type] ??= $vide;
            // Notée sans zone (saisie rapide, feuille de match) : comptée, mais pas sur la carte.
            if (!$a->isHorsCarte() && !str_starts_with($a->getUuid(), self::SANS_ZONE)) {
                ++$zones[$type][$a->getZx()][$a->getZy()];
                ++$zones['tout'][$a->getZx()][$a->getZy()];
            }
            $q = min(\count($quarts) - 1, intdiv(max(0, $a->getMinute() - 1), 15));
            $quarts[$q]['actions'][$type] = ($quarts[$q]['actions'][$type] ?? 0) + 1;
            if ($j = $a->getJoueur()) {
                $parJoueur[$j->getId()] ??= ['joueur' => $j, 'actions' => [], 'total' => 0];
                $parJoueur[$j->getId()]['actions'][$type] = ($parJoueur[$j->getId()]['actions'][$type] ?? 0) + 1;
                ++$parJoueur[$j->getId()]['total'];
            }
            // Passe décisive (notée avec le but) : comptée au passeur (« PD »).
            if ('B' === $type && ($passeur = $a->getDetails()['passeur'] ?? null) && ($p = $this->joueurs->find($passeur))) {
                ++$equipe['PD'];
                $parJoueur[$passeur] ??= ['joueur' => $p, 'actions' => [], 'total' => 0];
                $parJoueur[$passeur]['actions']['PD'] = ($parJoueur[$passeur]['actions']['PD'] ?? 0) + 1;
            }
        }
        uasort($parJoueur, fn ($a, $b) => [$a['joueur']->getNom(), $a['joueur']->getPrenom()] <=> [$b['joueur']->getNom(), $b['joueur']->getPrenom()]);

        return ['joueurs' => array_values($parJoueur), 'equipe' => $equipe, 'zones' => $zones, 'quarts' => $quarts, 'pour' => $equipe['B'], 'contre' => $equipe['BA']];
    }

    /** Début de l'identifiant des actions tirées de la feuille de match (pas enregistrées, pour le bilan seulement). */
    public const DE_LA_FEUILLE = 'feuille-';
    /** … et de celles sans place sur le terrain (carton, changement, but non placé) : absentes de la carte. */
    public const SANS_ZONE = 'feuille-sz-';

    /**
     * Actions du direct complétées par la feuille de match, pour le bilan : ce qui a été saisi ou corrigé sur la
     * feuille compte aussi dans les statistiques du match.
     * - Buts : ceux de la feuille dès qu'elle en a (elle fait foi : buteur, passeur, minute, façon de marquer,
     *   place sur le terrain) ; sinon ceux notés en direct.
     * - Cartons : ceux de la feuille pour les joueurs sans carton noté en direct.
     * - Remplacements : ceux de la feuille qui n'ont pas été notés en direct.
     *
     * @param list<ActionMatch> $actions
     *
     * @return list<ActionMatch>
     */
    public function avecFeuille(Rencontre $rencontre, array $actions): array
    {
        $mi = intdiv($this->duree($rencontre), 2);
        $n = 0;
        $nouvelle = function (string $type, ?Joueur $joueur, int $minute, ?array $place = null, ?array $details = null) use ($rencontre, $mi, &$n): ActionMatch {
            $a = (new ActionMatch($rencontre, ($place ? self::DE_LA_FEUILLE : self::SANS_ZONE) . ++$n))->setType($type)->setJoueur($joueur);
            // Place sur le terrain (en mètres) → zone du direct.
            $zx = $place ? min(ActionMatch::COLONNES - 1, max(0, (int) floor($place[0] / 17.5))) : 2;
            $zy = $place ? min(ActionMatch::COULOIRS - 1, max(0, (int) floor($place[1] / (68 / 3)))) : 1;

            return $a->placer($minute > $mi ? 2 : 1, $minute, $zx, $zy)->setDetails($details);
        };

        // Buts : la feuille fait foi dès qu'elle en a.
        $buts = $rencontre->getButs();
        if (\count($buts)) {
            $direct = array_values(array_filter($actions, fn (ActionMatch $a) => \in_array($a->getType(), ['B', 'BA'], true)));
            $actions = array_values(array_filter($actions, fn (ActionMatch $a) => !\in_array($a->getType(), ['B', 'BA'], true)));
            foreach ($buts as $b) {
                $type = $b->isAdverse() ? 'BA' : 'B';
                $csc = 'CSC' === $b->getConditions();
                $buteur = $b->isAdverse() || $csc ? null : $b->getButeur();
                $minute = self::minuteDuBut($b);
                // Pas placé sur la feuille : la place du même but noté en direct (tracé, sinon sa zone).
                $note = self::butCorrespondant($direct, $type, $buteur, $minute);
                if ($note?->isHorsCarte()) {
                    $note = null; // venu lui-même de la feuille, sans place
                }
                $trace = $b->getTrace() ?? (isset($note?->getDetails()['depart'], $note?->getDetails()['arrivee'])
                    ? ['depart' => $note->getDetails()['depart'], 'arrivee' => $note->getDetails()['arrivee']] : null);
                $details = ($trace ?? []) + array_filter([
                    'geste' => $csc ? 'csc' : ('Tête' === $b->getGeste() ? 'tete' : ($trace ? 'tir' : null)),
                    'passeur' => $b->isAdverse() ? null : $b->getPasseur()?->getId(),
                    // Corner, coup franc, penalty : d'après la « Situation » de la feuille, sinon notée en direct.
                    'situation' => ActionMatch::situationDeConditions($b->getConditions()) ?? $note?->getDetails()['situation'] ?? null,
                    // Endroit du coup franc indirect : seulement noté en direct (pas sur la feuille).
                    'coupFranc' => $note?->getDetails()['coupFranc'] ?? null,
                ]);
                $place = $trace['depart'] ?? ($note ? [($note->getZx() + 0.5) * 17.5, ($note->getZy() + 0.5) * 68 / 3] : null)
                    // Penalty : du point de penalty.
                    ?? ('penalty' === ($details['situation'] ?? null) ? [$b->isAdverse() ? 11.0 : 94.0, 34.0] : null);
                // Ni placé sur la feuille ni noté en direct : compté, mais pas sur la carte.
                $actions[] = $nouvelle($type, $buteur, $minute, $place, $details ?: null);
            }
        }

        // Cartons de la feuille, pour les joueurs sans carton noté en direct.
        $avertis = [];
        foreach ($actions as $a) {
            if (\in_array($a->getType(), ['CJ', 'CR', ActionMatch::EXCLUSION_TEMPORAIRE], true) && $a->getJoueur()) {
                $avertis[$a->getJoueur()->getId()] = true;
            }
        }
        foreach ($rencontre->getParticipations() as $p) {
            $j = $p->getJoueur();
            if (!$j || !$p->getCarton() || isset($avertis[$j->getId()])) {
                continue;
            }
            $minute = (int) floor(Rencontre::ordreMinute($p->getMinuteCarton()) ?? 0);
            $types = match ($p->getCarton()) {
                'J+R' => ['CJ', 'CR'],
                'RJ', 'RC' => ['CR'],
                'BL' => [ActionMatch::EXCLUSION_TEMPORAIRE],
                default => ['CJ'],
            };
            foreach ($types as $type) {
                $actions[] = $nouvelle($type, $j, $minute);
            }
        }

        // Remplacements de la feuille pas notés en direct (même sortant, entrant et minute).
        $notes = [];
        foreach ($actions as $a) {
            if (ActionMatch::CHANGEMENT === $a->getType()) {
                $notes[] = [$a->getJoueur()?->getId(), $a->getDetails()['entrant'] ?? null, $a->getMinute()];
            }
        }
        foreach ($rencontre->getRemplacements() as $r) {
            if (!$r->getSortant() || \in_array([$r->getSortant()->getId(), $r->getEntrant()?->getId(), $r->getMinute() ?? 0], $notes, true)) {
                continue;
            }
            $a = $nouvelle(ActionMatch::CHANGEMENT, $r->getSortant(), $r->getMinute() ?? 0);
            $actions[] = $a->setDetails(['entrant' => $r->getEntrant()?->getId(), 'motif' => $r->getMotif()]);
        }

        usort($actions, fn (ActionMatch $a, ActionMatch $b) => [$a->getPeriode(), $a->getMinute()] <=> [$b->getPeriode(), $b->getMinute()]);

        return $actions;
    }

    /** Action tirée de la feuille de match (pas notée en direct). */
    public static function deLaFeuille(ActionMatch $a): bool
    {
        return str_starts_with($a->getUuid(), self::DE_LA_FEUILLE);
    }

    /** Pourcentage de réussite d'un groupe (passes, duels…), null sans action. */
    public static function reussite(array $compte, string $groupe): ?int
    {
        [$bon, $rate] = self::REUSSITES[$groupe];
        $total = ($compte[$bon] ?? 0) + ($compte[$rate] ?? 0);

        return $total ? (int) round(100 * ($compte[$bon] ?? 0) / $total) : null;
    }
}
