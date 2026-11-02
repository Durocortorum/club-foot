<?php

namespace App\Repository;

use App\Entity\CoachSaison;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<CoachSaison>
 */
class CoachSaisonRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CoachSaison::class);
    }

    /** @return list<CoachSaison> coachs de l'équipe sur la saison, dans l'ordre de saisie */
    public function pourEquipe(int $categorieId, string $saison): array
    {
        return $this->createQueryBuilder('cs')
            ->addSelect('e')
            ->join('cs.educateur', 'e')
            ->andWhere('cs.categorie = :c')
            ->andWhere('cs.saison = :s')
            ->setParameter('c', $categorieId)
            ->setParameter('s', $saison)
            ->orderBy('cs.id')
            ->getQuery()
            ->getResult();
    }
}
