<?php

namespace App\Repository;

use App\Entity\Tache;
use App\Entity\Utilisateur;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Tache>
 */
class TacheRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Tache::class);
    }

    /**
     * Tâches non faites d'une personne : en retard et urgentes d'abord, puis par échéance.
     *
     * @return list<Tache>
     */
    public function ouvertesDe(Utilisateur $u, ?int $limite = null): array
    {
        return $this->createQueryBuilder('t')
            ->leftJoin('t.categorie', 'c')->addSelect('c')
            ->where('t.responsable = :u AND t.statut != :faite')
            ->setParameter('u', $u)->setParameter('faite', 'faite')
            ->addSelect('CASE WHEN t.echeance IS NULL THEN 1 ELSE 0 END AS HIDDEN sans_echeance')
            ->orderBy('t.urgente', 'DESC')->addOrderBy('sans_echeance')->addOrderBy('t.echeance')->addOrderBy('t.id')
            ->setMaxResults($limite)
            ->getQuery()->getResult();
    }

    public function compterOuvertesDe(Utilisateur $u): int
    {
        return (int) $this->createQueryBuilder('t')->select('COUNT(t.id)')
            ->where('t.responsable = :u AND t.statut != :faite')
            ->setParameter('u', $u)->setParameter('faite', 'faite')
            ->getQuery()->getSingleScalarResult();
    }
}
