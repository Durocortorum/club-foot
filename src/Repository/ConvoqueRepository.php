<?php

namespace App\Repository;

use App\Entity\Convoque;
use App\Entity\Joueur;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Convoque>
 */
class ConvoqueRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Convoque::class);
    }

    /**
     * Convocations envoyées à venir (match aujourd'hui ou plus tard) de ces joueurs, pour l'espace famille.
     *
     * @param iterable<Joueur> $joueurs
     *
     * @return list<Convoque>
     */
    public function aVenir(iterable $joueurs): array
    {
        $joueurs = [...$joueurs];
        if (!$joueurs) {
            return [];
        }

        return $this->createQueryBuilder('cq')
            ->addSelect('c', 'r')
            ->join('cq.convocation', 'c')
            ->join('c.rencontre', 'r')
            ->andWhere('cq.joueur IN (:joueurs)')->setParameter('joueurs', $joueurs)
            ->andWhere('c.envoyeeLe IS NOT NULL')
            ->andWhere('r.date >= :jour')->setParameter('jour', new \DateTimeImmutable('today'), Types::DATE_IMMUTABLE)
            ->orderBy('r.date')
            ->addOrderBy('r.heure')
            ->getQuery()
            ->getResult();
    }
}
