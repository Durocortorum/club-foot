<?php

namespace App\Service;

use App\Entity\ActionMatch;
use App\Entity\Convoque;
use App\Entity\Joueur;
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
    public const REUSSITES = ['passe' => ['BP', 'MP'], 'duel' => ['DG', 'DP'], 'controle' => ['BC', 'MC'], 'frappe' => ['FC', 'FH']];

    public function __construct(
        private readonly ConvocationRepository $convocations,
        private readonly JoueurRepository $joueurs,
    ) {
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
                $liste[$p->getJoueur()->getId()] = [$p->getJoueur(), $p->getNumero()];
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
        uasort($liste, fn ($a, $b) => [$a[1] ?? 99, $a[0]->getNom(), $a[0]->getPrenom()] <=> [$b[1] ?? 99, $b[0]->getNom(), $b[0]->getPrenom()]);

        return array_values(array_map(fn (array $l) => ['id' => $l[0]->getId(), 'nom' => (string) $l[0]->getNom(), 'prenom' => (string) $l[0]->getPrenom(), 'numero' => $l[1]], $liste));
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
        $equipe = array_fill_keys(array_merge(array_keys(ActionMatch::ACTIONS), array_keys(ActionMatch::ADVERSES)), 0);
        $vide = array_fill(0, ActionMatch::COLONNES, array_fill(0, ActionMatch::COULOIRS, 0));
        $zones = ['tout' => $vide];
        $duree = $this->duree($rencontre);
        $quarts = [];
        for ($debut = 0; $debut < $duree; $debut += 15) {
            $quarts[] = ['debut' => $debut, 'fin' => min($duree, $debut + 15), 'actions' => []];
        }
        foreach ($actions as $a) {
            $type = $a->getType();
            ++$equipe[$type];
            $zones[$type] ??= $vide;
            ++$zones[$type][$a->getZx()][$a->getZy()];
            ++$zones['tout'][$a->getZx()][$a->getZy()];
            $q = min(\count($quarts) - 1, intdiv(max(0, $a->getMinute() - 1), 15));
            $quarts[$q]['actions'][$type] = ($quarts[$q]['actions'][$type] ?? 0) + 1;
            if ($j = $a->getJoueur()) {
                $parJoueur[$j->getId()] ??= ['joueur' => $j, 'actions' => [], 'total' => 0];
                $parJoueur[$j->getId()]['actions'][$type] = ($parJoueur[$j->getId()]['actions'][$type] ?? 0) + 1;
                ++$parJoueur[$j->getId()]['total'];
            }
        }
        uasort($parJoueur, fn ($a, $b) => [$a['joueur']->getNom(), $a['joueur']->getPrenom()] <=> [$b['joueur']->getNom(), $b['joueur']->getPrenom()]);

        return ['joueurs' => array_values($parJoueur), 'equipe' => $equipe, 'zones' => $zones, 'quarts' => $quarts, 'pour' => $equipe['B'], 'contre' => $equipe['BA']];
    }

    /** Pourcentage de réussite d'un groupe (passes, duels…), null sans action. */
    public static function reussite(array $compte, string $groupe): ?int
    {
        [$bon, $rate] = self::REUSSITES[$groupe];
        $total = ($compte[$bon] ?? 0) + ($compte[$rate] ?? 0);

        return $total ? (int) round(100 * ($compte[$bon] ?? 0) / $total) : null;
    }
}
