<?php

namespace App\Repository;

use App\Entity\Categorie;
use App\Entity\Joueur;
use App\Entity\Ressenti;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Ressenti>
 */
class RessentiRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Ressenti::class);
    }

    /**
     * Ressentis des joueurs d'une équipe entre deux dates (incluses).
     *
     * @return list<Ressenti>
     */
    public function deLEquipe(Categorie $categorie, \DateTimeImmutable $debut, \DateTimeImmutable $fin): array
    {
        return $this->createQueryBuilder('r')
            ->join('r.joueur', 'j')->addSelect('j')
            ->andWhere('j.categorie = :c')->setParameter('c', $categorie)
            ->andWhere('r.date BETWEEN :debut AND :fin')
            ->setParameter('debut', $debut, Types::DATE_IMMUTABLE)->setParameter('fin', $fin, Types::DATE_IMMUTABLE)
            ->orderBy('r.date')
            ->getQuery()->getResult();
    }

    /**
     * Derniers ressentis de ces joueurs (espace famille).
     *
     * @param list<Joueur> $joueurs
     *
     * @return list<Ressenti>
     */
    public function recents(array $joueurs, \DateTimeImmutable $depuis): array
    {
        if (!$joueurs) {
            return [];
        }

        return $this->createQueryBuilder('r')
            ->andWhere('r.joueur IN (:j)')->setParameter('j', $joueurs)
            ->andWhere('r.date >= :depuis')->setParameter('depuis', $depuis, Types::DATE_IMMUTABLE)
            ->orderBy('r.date', 'DESC')
            ->getQuery()->getResult();
    }
}
