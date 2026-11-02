<?php

namespace App\Repository;

use App\Entity\Rencontre;
use App\Entity\SemainePresence;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\Tools\Pagination\Paginator;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<SemainePresence>
 */
class SemainePresenceRepository extends ServiceEntityRepository
{
    public const PAR_PAGE = 30;

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SemainePresence::class);
    }

    /**
     * @param list<string> $secteurs
     */
    public function rechercher(array $secteurs, ?string $saison, ?int $categorieId, int $page): Paginator
    {
        $qb = $this->createQueryBuilder('s')
            ->addSelect('c')
            ->join('s.categorie', 'c')
            ->andWhere('c.secteur IN (:secteurs)')
            ->setParameter('secteurs', $secteurs ?: ['-'])
            ->orderBy('s.lundi', 'DESC')
            ->addOrderBy('c.ordre')
            ->setFirstResult(($page - 1) * self::PAR_PAGE)
            ->setMaxResults(self::PAR_PAGE);

        if ($saison) {
            [$debut, $fin] = Rencontre::bornesSaison($saison);
            $qb->andWhere('s.lundi BETWEEN :debut AND :fin')
                ->setParameter('debut', $debut->format('Y-m-d'))
                ->setParameter('fin', $fin->format('Y-m-d'));
        }
        if ($categorieId) {
            $qb->andWhere('c.id = :cat')->setParameter('cat', $categorieId);
        }

        return new Paginator($qb);
    }

    /**
     * Saisons où chaque catégorie a des feuilles de présence (écran « Archives »).
     *
     * @param list<string> $secteurs
     *
     * @return array<int, list<string>> id de la catégorie => saisons, de la plus ancienne à la plus récente
     */
    public function saisonsParCategorie(array $secteurs): array
    {
        $lignes = $this->createQueryBuilder('s')
            ->select('c.id AS categorie, s.lundi')
            ->join('s.categorie', 'c')
            ->andWhere('c.secteur IN (:secteurs)')
            ->setParameter('secteurs', $secteurs ?: ['-'])
            ->getQuery()
            ->getArrayResult();

        return RencontreRepository::regrouperParSaison($lignes, 'lundi');
    }

    /** @return list<string> */
    public function saisons(): array
    {
        $bornes = $this->createQueryBuilder('s')
            ->select('MIN(s.lundi) AS debut, MAX(s.lundi) AS fin')
            ->getQuery()
            ->getSingleResult();

        return RencontreRepository::saisonsEntre($bornes['debut'], $bornes['fin']);
    }
}
