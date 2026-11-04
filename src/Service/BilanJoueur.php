<?php

namespace App\Service;

use App\Entity\But;
use App\Entity\Joueur;
use App\Entity\Participation;
use App\Repository\ButRepository;
use App\Repository\ParticipationRepository;

/**
 * Statistiques de match d'un joueur, saison par saison.
 */
class BilanJoueur
{
    private const VIDE = ['matchs' => 0, 'titularisations' => 0, 'minutes' => 0, 'minutesPossibles' => 0, 'note' => null, 'buts' => 0, 'passes' => 0, 'jaunes' => 0, 'rouges' => 0, 'exclusions' => 0];

    public function __construct(
        private readonly ButRepository $buts,
        private readonly ParticipationRepository $participations,
    ) {
    }

    /**
     * minutesPossibles : durée cumulée des matchs où il figurait sur la feuille, pour le pourcentage
     * de minutes jouées (la durée d'un match, non saisie, est le plus long temps de jeu de sa feuille).
     *
     * @param list<Participation> $participations du joueur (voir ParticipationRepository::parJoueur)
     *
     * @return array<string, array{matchs: int, titularisations: int, minutes: int, minutesPossibles: int, note: float|null, buts: int, passes: int, jaunes: int, rouges: int, exclusions: int}>
     *               par saison, de la plus récente à la plus ancienne, puis « Total » (absent sans aucun match)
     */
    public function parSaison(Joueur $joueur, array $participations): array
    {
        $bilan = [];
        $notes = [];
        $durees = $this->durees($participations);

        foreach ($participations as $p) {
            $saison = $p->getRencontre()->getSaison() ?? '?';
            $bilan[$saison] ??= self::VIDE;
            $bilan[$saison]['matchs'] += $p->getTempsJeu() > 0 ? 1 : 0;
            $bilan[$saison]['titularisations'] += $p->isTitulaire() ? 1 : 0;
            $bilan[$saison]['minutes'] += (int) $p->getTempsJeu();
            $bilan[$saison]['minutesPossibles'] += $durees[$p->getRencontre()->getId()] ?? 0;
            $bilan[$saison]['jaunes'] += \in_array($p->getCarton(), Participation::CARTONS_JAUNES, true) ? 1 : 0;
            $bilan[$saison]['rouges'] += \in_array($p->getCarton(), Participation::CARTONS_ROUGES, true) ? 1 : 0;
            $bilan[$saison]['exclusions'] += 'BL' === $p->getCarton() ? 1 : 0;
            if (null !== $p->getNote()) {
                $notes[$saison][] = $p->getNote();
            }
        }

        $buts = $this->buts->createQueryBuilder('b')
            ->addSelect('r')
            ->join('b.rencontre', 'r')
            ->andWhere('b.buteur = :j OR b.passeur = :j')
            ->setParameter('j', $joueur)
            ->getQuery()
            ->getResult();
        foreach ($buts as $b) {
            /** @var But $b */
            $saison = $b->getRencontre()->getSaison() ?? '?';
            $bilan[$saison] ??= self::VIDE;
            $bilan[$saison]['buts'] += $b->getButeur() === $joueur ? 1 : 0;
            $bilan[$saison]['passes'] += $b->getPasseur() === $joueur ? 1 : 0;
        }

        foreach ($notes as $saison => $n) {
            $bilan[$saison]['note'] = round(array_sum($n) / \count($n), 1);
        }
        krsort($bilan);

        if ($bilan) {
            // Toutes les saisons ayant des statistiques de match (rien pour l'école de foot, sans feuilles de match).
            $total = self::VIDE;
            foreach ($bilan as $b) {
                foreach ($total as $cle => $valeur) {
                    $total[$cle] = 'note' === $cle ? null : $valeur + $b[$cle];
                }
            }
            $toutes = array_merge(...array_values($notes ?: [[]]));
            $total['note'] = $toutes ? round(array_sum($toutes) / \count($toutes), 1) : null;
            $bilan['Total'] = $total;
        }

        return $bilan;
    }

    /**
     * Durée de chaque match de la liste : le plus long temps de jeu de sa feuille de match.
     *
     * @param list<Participation> $participations
     *
     * @return array<int, int> minutes par identifiant de match
     */
    private function durees(array $participations): array
    {
        if (!$participations) {
            return [];
        }
        $lignes = $this->participations->createQueryBuilder('p')
            ->select('IDENTITY(p.rencontre) AS rencontre, MAX(p.tempsJeu) AS duree')
            ->andWhere('p.rencontre IN (:r)')
            ->setParameter('r', array_map(fn (Participation $p) => $p->getRencontre(), $participations))
            ->groupBy('p.rencontre')
            ->getQuery()
            ->getArrayResult();

        return array_column(array_map(fn ($l) => ['r' => (int) $l['rencontre'], 'd' => (int) $l['duree']], $lignes), 'd', 'r');
    }
}
