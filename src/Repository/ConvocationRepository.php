<?php

namespace App\Repository;

use App\Entity\Convocation;
use App\Entity\Rencontre;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Convocation>
 */
class ConvocationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Convocation::class);
    }

    public function pour(Rencontre $rencontre): ?Convocation
    {
        return $this->findOneBy(['rencontre' => $rencontre]);
    }
}
