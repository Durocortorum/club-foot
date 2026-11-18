<?php

namespace App\Repository;

use App\Entity\ActionMatch;
use App\Entity\Joueur;
use App\Entity\Rencontre;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<ActionMatch> */
class ActionMatchRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ActionMatch::class);
    }

    /** @return list<ActionMatch> actions du match, dans l'ordre du jeu */
    public function duMatch(Rencontre $rencontre): array
    {
        return $this->createQueryBuilder('a')
            ->andWhere('a.rencontre = :r')->setParameter('r', $rencontre)
            ->orderBy('a.periode')->addOrderBy('a.minute')->addOrderBy('a.notee')->addOrderBy('a.id')
            ->getQuery()->getResult();
    }

    /**
     * Actions de plusieurs matchs, en une requête (statistiques de la saison).
     *
     * @param list<Rencontre> $rencontres
     *
     * @return list<ActionMatch> dans l'ordre du jeu, match par match
     */
    public function desMatchs(array $rencontres): array
    {
        if (!$rencontres) {
            return [];
        }

        return $this->createQueryBuilder('a')
            ->addSelect('j')->leftJoin('a.joueur', 'j')
            ->andWhere('a.rencontre IN (:r)')->setParameter('r', $rencontres)
            ->orderBy('IDENTITY(a.rencontre)')->addOrderBy('a.periode')->addOrderBy('a.minute')->addOrderBy('a.notee')->addOrderBy('a.id')
            ->getQuery()->getResult();
    }

    /**
     * Matchs où le joueur a au moins une action notée en direct.
     *
     * @return list<Rencontre>
     */
    public function rencontresDe(Joueur $joueur): array
    {
        return $this->getEntityManager()->createQueryBuilder()
            ->select('r')->from(Rencontre::class, 'r')
            ->andWhere('r.id IN (SELECT IDENTITY(a.rencontre) FROM ' . ActionMatch::class . ' a WHERE a.joueur = :j)')
            ->setParameter('j', $joueur)
            ->getQuery()->getResult();
    }
}
