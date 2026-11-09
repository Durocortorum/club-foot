<?php

namespace App\Repository;

use App\Entity\Categorie;
use App\Entity\Evenement;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Evenement>
 */
class EvenementRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Evenement::class);
    }

    /**
     * Événements qui ont lieu (au moins un jour) entre ces deux dates, pour ces équipes ou tout le club.
     *
     * @param list<Categorie>|null $categories null : tous les événements du club
     *
     * @return list<Evenement>
     */
    public function entre(\DateTimeImmutable $debut, \DateTimeImmutable $fin, ?array $categories = null, bool $famillesSeulement = false): array
    {
        $qb = $this->createQueryBuilder('e')
            ->leftJoin('e.categories', 'c')->addSelect('c')
            ->andWhere('e.debut < :apres')->setParameter('apres', $fin->setTime(0, 0)->modify('+1 day'))
            ->andWhere('COALESCE(e.fin, e.debut) >= :debut')->setParameter('debut', $debut->setTime(0, 0))
            ->orderBy('e.debut');
        if ($famillesSeulement) {
            $qb->andWhere('e.visibleFamilles = true');
        }
        $evenements = $qb->getQuery()->getResult();

        return null === $categories ? $evenements : array_values(array_filter($evenements, fn (Evenement $e) => $e->concerne($categories)));
    }
}
