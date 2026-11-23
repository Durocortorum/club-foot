<?php

namespace App\Repository;

use App\Entity\Rencontre;
use App\Entity\TableauTactique;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<TableauTactique>
 */
class TableauTactiqueRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, TableauTactique::class);
    }

    /** @return list<TableauTactique> tableaux du match, dans l'ordre de présentation */
    public function duMatch(Rencontre $rencontre, bool $partagesSeulement = false): array
    {
        return $this->findBy(['rencontre' => $rencontre] + ($partagesSeulement ? ['partage' => true] : []), ['ordre' => 'ASC', 'id' => 'ASC']);
    }

    /**
     * Matchs (parmi ceux donnés) qui ont au moins un tableau montré aux familles.
     *
     * @param list<Rencontre> $rencontres
     *
     * @return array<int, int> nombre de tableaux partagés par id de match
     */
    public function partagesPar(array $rencontres): array
    {
        if (!$rencontres) {
            return [];
        }
        $lignes = $this->createQueryBuilder('t')
            ->select('IDENTITY(t.rencontre) AS r, COUNT(t.id) AS n')
            ->andWhere('t.rencontre IN (:r)')->setParameter('r', $rencontres)
            ->andWhere('t.partage = true')
            ->groupBy('t.rencontre')
            ->getQuery()->getArrayResult();

        return array_column(array_map(fn ($l) => [(int) $l['r'], (int) $l['n']], $lignes), 1, 0);
    }

    /** Prochain numéro d'ordre : le nouveau tableau se range en dernier. */
    public function ordreSuivant(Rencontre $rencontre): int
    {
        return 1 + (int) $this->createQueryBuilder('t')->select('MAX(t.ordre)')
            ->andWhere('t.rencontre = :r')->setParameter('r', $rencontre)->getQuery()->getSingleScalarResult();
    }
}
