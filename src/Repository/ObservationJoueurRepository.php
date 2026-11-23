<?php

namespace App\Repository;

use App\Entity\Joueur;
use App\Entity\ObservationJoueur;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ObservationJoueur>
 */
class ObservationJoueurRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ObservationJoueur::class);
    }

    /** @return list<ObservationJoueur> observations entre deux dates, la plus récente d'abord */
    public function duJoueur(Joueur $joueur, \DateTimeImmutable $debut, \DateTimeImmutable $fin, bool $partageesSeulement = false): array
    {
        $qb = $this->createQueryBuilder('o')
            ->andWhere('o.joueur = :j')->setParameter('j', $joueur)
            ->andWhere('o.date BETWEEN :d AND :f')->setParameter('d', $debut)->setParameter('f', $fin)
            ->orderBy('o.date', 'DESC')->addOrderBy('o.id', 'DESC');
        if ($partageesSeulement) {
            $qb->andWhere('o.partage = true');
        }

        return $qb->getQuery()->getResult();
    }
}
