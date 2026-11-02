<?php

namespace App\Service;

use App\Entity\But;
use App\Entity\Joueur;
use App\Entity\Participation;
use App\Repository\ButRepository;

/**
 * Statistiques de match d'un joueur, saison par saison.
 */
class BilanJoueur
{
    public function __construct(private readonly ButRepository $buts)
    {
    }

    /**
     * @param list<Participation> $participations du joueur (voir ParticipationRepository::parJoueur)
     *
     * @return array<string, array{matchs: int, titularisations: int, minutes: int, note: float|null, buts: int, passes: int, jaunes: int, rouges: int, exclusions: int}>
     *               par saison, de la plus récente à la plus ancienne
     */
    public function parSaison(Joueur $joueur, array $participations): array
    {
        $vide = ['matchs' => 0, 'titularisations' => 0, 'minutes' => 0, 'note' => null, 'buts' => 0, 'passes' => 0, 'jaunes' => 0, 'rouges' => 0, 'exclusions' => 0];
        $bilan = [];
        $notes = [];

        foreach ($participations as $p) {
            $saison = $p->getRencontre()->getSaison() ?? '?';
            $bilan[$saison] ??= $vide;
            $bilan[$saison]['matchs'] += $p->getTempsJeu() > 0 ? 1 : 0;
            $bilan[$saison]['titularisations'] += $p->isTitulaire() ? 1 : 0;
            $bilan[$saison]['minutes'] += (int) $p->getTempsJeu();
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
            $bilan[$saison] ??= $vide;
            $bilan[$saison]['buts'] += $b->getButeur() === $joueur ? 1 : 0;
            $bilan[$saison]['passes'] += $b->getPasseur() === $joueur ? 1 : 0;
        }

        foreach ($notes as $saison => $n) {
            $bilan[$saison]['note'] = round(array_sum($n) / \count($n), 1);
        }
        krsort($bilan);

        return $bilan;
    }
}
