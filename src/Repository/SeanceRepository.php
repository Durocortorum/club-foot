<?php

namespace App\Repository;

use App\Entity\Rencontre;
use App\Entity\Seance;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\Tools\Pagination\Paginator;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Seance>
 */
class SeanceRepository extends ServiceEntityRepository
{
    public const PAR_PAGE = 30;

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Seance::class);
    }

    /**
     * @param list<string> $secteurs
     */
    public function rechercher(array $secteurs, ?string $saison, ?int $categorieId, ?string $texte, int $page): Paginator
    {
        $qb = $this->createQueryBuilder('s')
            ->addSelect('c')
            ->join('s.categorie', 'c')
            ->andWhere('c.secteur IN (:secteurs)')
            ->setParameter('secteurs', $secteurs ?: ['-'])
            ->orderBy('s.date', 'DESC')
            ->addOrderBy('c.ordre')
            ->setFirstResult(($page - 1) * self::PAR_PAGE)
            ->setMaxResults(self::PAR_PAGE);

        if ($saison) {
            [$debut, $fin] = Rencontre::bornesSaison($saison);
            $qb->andWhere('s.date BETWEEN :debut AND :fin')
                ->setParameter('debut', $debut->format('Y-m-d'))
                ->setParameter('fin', $fin->format('Y-m-d'));
        }
        if ($categorieId) {
            $qb->andWhere('c.id = :cat')->setParameter('cat', $categorieId);
        }
        if ($texte) {
            $qb->andWhere('s.theme LIKE :t OR EXISTS (SELECT e.id FROM App\Entity\Exercice e WHERE e.seance = s AND e.titre LIKE :t)')
                ->setParameter('t', '%' . $texte . '%');
        }

        return new Paginator($qb);
    }

    /**
     * Séances où un exercice peut être copié, les plus récentes d'abord.
     *
     * @param list<string> $secteurs
     *
     * @return list<Seance>
     */
    public function destinations(array $secteurs, int $limite = 40): array
    {
        return $this->createQueryBuilder('s')
            ->addSelect('c')
            ->join('s.categorie', 'c')
            ->andWhere('c.secteur IN (:secteurs)')
            ->setParameter('secteurs', $secteurs ?: ['-'])
            ->orderBy('s.date', 'DESC')
            ->setMaxResults($limite)
            ->getQuery()
            ->getResult();
    }

    /** @return list<string> */
    public function saisons(): array
    {
        $bornes = $this->createQueryBuilder('s')
            ->select('MIN(s.date) AS debut, MAX(s.date) AS fin')
            ->getQuery()
            ->getSingleResult();

        return RencontreRepository::saisonsEntre($bornes['debut'], $bornes['fin']);
    }
}
