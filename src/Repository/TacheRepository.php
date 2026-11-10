<?php

namespace App\Repository;

use App\Entity\Tache;
use App\Entity\Utilisateur;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
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

    /**
     * Tâches confiées à ces joueurs, pour l'espace famille : non faites, et faites depuis moins de 15 jours.
     *
     * @param list<\App\Entity\Joueur> $joueurs
     *
     * @return list<Tache>
     */
    public function pourJoueurs(array $joueurs): array
    {
        if (!$joueurs) {
            return [];
        }

        return $this->createQueryBuilder('t')
            ->where('t.joueur IN (:joueurs)')
            ->andWhere('t.statut != :faite OR t.faiteLe >= :depuis')
            ->setParameter('joueurs', $joueurs)->setParameter('faite', 'faite')
            ->setParameter('depuis', new \DateTimeImmutable('-15 days'))
            ->addSelect('CASE WHEN t.statut = :faite THEN 1 ELSE 0 END AS HIDDEN faite_en_dernier')
            ->addSelect('CASE WHEN t.echeance IS NULL THEN 1 ELSE 0 END AS HIDDEN sans_echeance')
            ->orderBy('faite_en_dernier')->addOrderBy('sans_echeance')->addOrderBy('t.echeance')
            ->getQuery()->getResult();
    }

    /**
     * Tâches non faites confiées à d'autres (encadrement ou joueurs) que ce compte peut voir, pour l'accueil.
     *
     * @return list<Tache>
     */
    public function ouvertesDesAutres(Utilisateur $u, int $limite): array
    {
        return self::visibles($this->createQueryBuilder('t'), $u)
            ->leftJoin('t.categorie', 'c')->addSelect('c')
            ->leftJoin('t.responsable', 'r')->addSelect('r')
            ->leftJoin('t.joueur', 'j')->addSelect('j')
            ->andWhere('t.statut != :faite')->setParameter('faite', 'faite')
            ->andWhere('t.responsable IS NULL OR t.responsable != :moi')->setParameter('moi', $u)
            ->addSelect('CASE WHEN t.echeance IS NULL THEN 1 ELSE 0 END AS HIDDEN sans_echeance')
            ->orderBy('t.urgente', 'DESC')->addOrderBy('sans_echeance')->addOrderBy('t.echeance')->addOrderBy('t.id')
            ->setMaxResults($limite)
            ->getQuery()->getResult();
    }

    /** Tâches non faites du club (à faire et en cours) que ce compte peut voir. */
    public function compterOuvertes(Utilisateur $u): int
    {
        return (int) self::visibles($this->createQueryBuilder('t')->select('COUNT(t.id)'), $u)
            ->andWhere('t.statut != :faite')->setParameter('faite', 'faite')
            ->getQuery()->getSingleScalarResult();
    }

    /** Écarte les tâches privées dont ce compte n'est ni l'auteur ni la personne chargée. */
    public static function visibles(QueryBuilder $qb, Utilisateur $u, string $alias = 't'): QueryBuilder
    {
        return $qb->andWhere("$alias.privee = false OR $alias.creePar = :visible_par OR $alias.responsable = :visible_par")
            ->setParameter('visible_par', $u);
    }

    public function compterOuvertesDe(Utilisateur $u): int
    {
        return (int) $this->createQueryBuilder('t')->select('COUNT(t.id)')
            ->where('t.responsable = :u AND t.statut != :faite')
            ->setParameter('u', $u)->setParameter('faite', 'faite')
            ->getQuery()->getSingleScalarResult();
    }
}
