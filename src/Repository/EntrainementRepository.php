<?php

namespace App\Repository;

use App\Entity\Categorie;
use App\Entity\Entrainement;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Entrainement>
 */
class EntrainementRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Entrainement::class);
    }

    /**
     * Entraînements de ces catégories entre deux dates (incluses), dans l'ordre.
     *
     * @param list<Categorie> $categories
     *
     * @return list<Entrainement>
     */
    public function entre(array $categories, \DateTimeImmutable $debut, \DateTimeImmutable $fin): array
    {
        if (!$categories) {
            return [];
        }

        return $this->createQueryBuilder('e')
            ->leftJoin('e.absences', 'a')->addSelect('a')
            ->andWhere('e.categorie IN (:c)')->setParameter('c', $categories)
            ->andWhere('e.date BETWEEN :debut AND :fin')
            ->setParameter('debut', $debut, Types::DATE_IMMUTABLE)
            ->setParameter('fin', $fin, Types::DATE_IMMUTABLE)
            ->orderBy('e.date')->addOrderBy('e.heureDebut')
            ->getQuery()->getResult();
    }
}
