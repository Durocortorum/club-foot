<?php

namespace App\Repository;

use App\Entity\Blessure;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\Tools\Pagination\Paginator;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Blessure>
 */
class BlessureRepository extends ServiceEntityRepository
{
    public const PAR_PAGE = 30;

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Blessure::class);
    }

    /**
     * @param string $statut 'en_cours' ou 'toutes'
     */
    public function rechercher(string $statut, ?string $texte, ?int $categorieId, int $page): Paginator
    {
        $qb = $this->createQueryBuilder('b')
            ->addSelect('j', 'c')
            ->join('b.joueur', 'j')
            ->leftJoin('j.categorie', 'c')
            ->orderBy('b.date', 'DESC')
            ->addOrderBy('b.id', 'DESC')
            ->setFirstResult(($page - 1) * self::PAR_PAGE)
            ->setMaxResults(self::PAR_PAGE);

        if ('en_cours' === $statut) {
            $qb->andWhere('b.dateReprise IS NULL OR b.dateReprise > CURRENT_DATE()')
                ->andWhere('j.ancien = false');
        }
        if ($texte) {
            $qb->andWhere('j.nom LIKE :t OR j.prenom LIKE :t OR b.type LIKE :t OR b.zone LIKE :t')
                ->setParameter('t', '%' . $texte . '%');
        }
        if ($categorieId) {
            $qb->andWhere('c.id = :cat')->setParameter('cat', $categorieId);
        }

        return new Paginator($qb);
    }

    /**
     * Nombre de blessures par type, pour les statistiques de l'infirmerie.
     *
     * @return array<string, int>
     */
    public function compterParType(int $limite = 10): array
    {
        $lignes = $this->createQueryBuilder('b')
            ->select('b.type, COUNT(b.id) AS total')
            ->andWhere('b.type IS NOT NULL')
            ->groupBy('b.type')
            ->orderBy('total', 'DESC')
            ->setMaxResults($limite)
            ->getQuery()
            ->getArrayResult();

        return array_column($lignes, 'total', 'type');
    }
}
