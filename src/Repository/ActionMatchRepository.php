<?php

namespace App\Repository;

use App\Entity\ActionMatch;
use App\Entity\Rencontre;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<ActionMatch> */
class ActionMatchRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ActionMatch::class);
    }

    /** @return list<ActionMatch> actions du match, dans l'ordre du jeu */
    public function duMatch(Rencontre $rencontre): array
    {
        return $this->createQueryBuilder('a')
            ->andWhere('a.rencontre = :r')->setParameter('r', $rencontre)
            ->orderBy('a.periode')->addOrderBy('a.minute')->addOrderBy('a.notee')->addOrderBy('a.id')
            ->getQuery()->getResult();
    }
}
