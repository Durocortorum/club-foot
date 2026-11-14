<?php

namespace App\Repository;

use App\Entity\ConnexionHelloAsso;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ConnexionHelloAsso>
 */
class ConnexionHelloAssoRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ConnexionHelloAsso::class);
    }
}
