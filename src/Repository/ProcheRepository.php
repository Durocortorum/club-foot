<?php

namespace App\Repository;

use App\Entity\Joueur;
use App\Entity\Proche;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Proche>
 */
class ProcheRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Proche::class);
    }

    /** @return list<Proche> */
    public function duJoueur(Joueur $joueur): array
    {
        return $this->findBy(['joueur' => $joueur], ['id' => 'ASC']);
    }
}
