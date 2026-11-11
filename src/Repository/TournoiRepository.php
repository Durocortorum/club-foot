<?php

namespace App\Repository;

use App\Entity\Categorie;
use App\Entity\Tournoi;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Tournoi>
 */
class TournoiRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Tournoi::class);
    }

    /**
     * Tournois qui ont lieu (au moins un jour) entre deux dates, des équipes données (null : toutes).
     *
     * @param list<Categorie>|null $categories
     *
     * @return list<Tournoi>
     */
    public function entre(\DateTimeImmutable $debut, \DateTimeImmutable $fin, ?array $categories = null): array
    {
        $qb = $this->createQueryBuilder('t')
            ->leftJoin('t.categorie', 'c')->addSelect('c')
            ->andWhere('t.debut <= :fin')->setParameter('fin', $fin->setTime(0, 0))
            ->andWhere('COALESCE(t.fin, t.debut) >= :debut')->setParameter('debut', $debut->setTime(0, 0))
            ->orderBy('t.debut');
        if (null !== $categories) {
            $qb->andWhere('t.categorie IN (:categories)')->setParameter('categories', $categories ?: [0]);
        }

        return $qb->getQuery()->getResult();
    }
}
