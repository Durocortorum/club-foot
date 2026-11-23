<?php

namespace App\Repository;

use App\Entity\CombinaisonCpa;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<CombinaisonCpa>
 */
class CombinaisonCpaRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CombinaisonCpa::class);
    }

    /** @return list<CombinaisonCpa> combinaisons d'un type (et d'un côté si donné), offensives d'abord, par titre */
    public function duType(string $type, ?string $cote = null): array
    {
        return $this->findBy(['type' => $type] + ($cote ? ['cote' => $cote] : []), ['cote' => 'DESC', 'titre' => 'ASC']);
    }

    /** @return array<string, int> nombre de combinaisons par type */
    public function compterParType(): array
    {
        $lignes = $this->createQueryBuilder('c')->select('c.type, COUNT(c.id) AS n')->groupBy('c.type')->getQuery()->getArrayResult();

        return array_column(array_map(fn ($l) => [$l['type'], (int) $l['n']], $lignes), 1, 0);
    }
}
