<?php

namespace App\Repository;

use App\Entity\Categorie;
use App\Entity\Stage;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Stage>
 */
class StageRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Stage::class);
    }

    /**
     * Stages qui ont au moins un jour entre ces deux dates, pour ces catégories (null = toutes) ou tout le club.
     *
     * @param list<Categorie>|null $categories
     *
     * @return list<Stage>
     */
    public function entre(\DateTimeImmutable $debut, \DateTimeImmutable $fin, ?array $categories = null): array
    {
        $stages = $this->createQueryBuilder('s')
            ->leftJoin('s.categories', 'c')->addSelect('c')
            ->andWhere('s.debut <= :fin')->setParameter('fin', $fin->setTime(0, 0))
            ->andWhere('COALESCE(s.fin, s.debut) >= :debut')->setParameter('debut', $debut->setTime(0, 0))
            ->orderBy('s.debut')
            ->getQuery()->getResult();

        return null === $categories ? $stages : array_values(array_filter($stages, fn (Stage $s) => $s->concerne($categories)));
    }
}
