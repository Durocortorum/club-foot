<?php

namespace App\Repository;

use App\Entity\Educateur;
use App\Entity\Joueur;
use App\Entity\Participation;
use App\Entity\Presence;
use App\Entity\Rencontre;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\ORM\Tools\Pagination\Paginator;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Joueur>
 */
class JoueurRepository extends ServiceEntityRepository
{
    public const PAR_PAGE = 30;

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Joueur::class);
    }

    /**
     * Liste filtrée des joueurs, limitée aux secteurs autorisés pour l'utilisateur.
     *
     * @param list<string> $secteurs secteurs visibles (voir Categorie::SECTEURS)
     * @param string       $statut   'actifs', 'anciens' ou 'tous'
     * @param string|null  $saison   joueurs inscrits sur une feuille de match ou de présence de la saison ;
     *                               la catégorie désigne alors l'équipe de ces feuilles, pas la catégorie actuelle
     */
    public function rechercher(array $secteurs, ?string $texte, ?int $categorieId, string $statut, int $page, ?string $saison = null): Paginator
    {
        $qb = $this->filtrer($secteurs, $texte, $categorieId, $statut, $saison)
            ->addSelect('c', 'e')
            ->orderBy('c.ordre')
            ->addOrderBy('j.nom')
            ->addOrderBy('j.prenom')
            ->setFirstResult(($page - 1) * self::PAR_PAGE)
            ->setMaxResults(self::PAR_PAGE);

        return new Paginator($qb);
    }

    /**
     * Éducateurs des joueurs de la liste filtrée (mêmes critères que rechercher), du plus suivi au moins suivi.
     *
     * @param list<string> $secteurs
     *
     * @return list<array{educateur: Educateur, joueurs: int}>
     */
    public function educateurs(array $secteurs, ?string $texte, ?int $categorieId, string $statut): array
    {
        return $this->compterEducateurs($this->filtrer($secteurs, $texte, $categorieId, $statut, null));
    }

    /**
     * Éducateurs indiqués sur les fiches de ces joueurs, du plus suivi au moins suivi.
     *
     * @param list<int> $ids
     *
     * @return list<array{educateur: Educateur, joueurs: int}>
     */
    public function educateursDe(array $ids): array
    {
        return $this->compterEducateurs($this->createQueryBuilder('j')->andWhere('j.id IN (:ids)')->setParameter('ids', $ids ?: [0]));
    }

    /** @return list<array{educateur: Educateur, joueurs: int}> */
    private function compterEducateurs(QueryBuilder $qb): array
    {
        $lignes = $qb
            ->select('IDENTITY(j.educateur) AS id, COUNT(j.id) AS joueurs')
            ->andWhere('j.educateur IS NOT NULL')
            ->groupBy('j.educateur')
            ->orderBy('joueurs', 'DESC')
            ->getQuery()
            ->getArrayResult();
        $parId = [];
        foreach ($this->getEntityManager()->getRepository(Educateur::class)->findBy(['id' => array_column($lignes, 'id')]) as $e) {
            $parId[$e->getId()] = $e;
        }

        return array_map(fn (array $l) => ['educateur' => $parId[$l['id']], 'joueurs' => (int) $l['joueurs']], $lignes);
    }

    /** @param list<string> $secteurs */
    private function filtrer(array $secteurs, ?string $texte, ?int $categorieId, string $statut, ?string $saison): QueryBuilder
    {
        $qb = $this->createQueryBuilder('j')
            ->leftJoin('j.categorie', 'c')
            ->leftJoin('j.educateur', 'e')
            ->andWhere('c.secteur IN (:secteurs)')
            ->setParameter('secteurs', $secteurs ?: ['-']);

        if ($texte) {
            $qb->andWhere('j.nom LIKE :t OR j.prenom LIKE :t OR j.licence LIKE :t')
                ->setParameter('t', '%' . $texte . '%');
        }
        if ($saison) {
            [$debut, $fin] = Rencontre::bornesSaison($saison);
            $matchs = $this->getEntityManager()->createQueryBuilder()
                ->select('1')->from(Participation::class, 'pm')->join('pm.rencontre', 'rm')
                ->andWhere('pm.joueur = j')->andWhere('rm.date BETWEEN :debut AND :fin');
            $presences = $this->getEntityManager()->createQueryBuilder()
                ->select('1')->from(Presence::class, 'pp')->join('pp.semaine', 'sp')
                ->andWhere('pp.joueur = j')->andWhere('sp.lundi BETWEEN :debut AND :fin');
            if ($categorieId) {
                $matchs->andWhere('rm.categorie = :cat');
                $presences->andWhere('sp.categorie = :cat');
                $qb->setParameter('cat', $categorieId);
            }
            $qb->andWhere($qb->expr()->orX($qb->expr()->exists($matchs->getDQL()), $qb->expr()->exists($presences->getDQL())))
                ->setParameter('debut', $debut->format('Y-m-d'))
                ->setParameter('fin', $fin->format('Y-m-d'));
        } elseif ($categorieId) {
            $qb->andWhere('c.id = :cat')->setParameter('cat', $categorieId);
        }
        if ('actifs' === $statut) {
            $qb->andWhere('j.ancien = false');
        } elseif ('anciens' === $statut) {
            $qb->andWhere('j.ancien = true');
        }

        return $qb;
    }

    /**
     * Joueurs actifs des secteurs visibles (d'une seule catégorie si $categorieId), par catégorie puis par nom.
     *
     * @param list<string> $secteurs
     *
     * @return list<Joueur>
     */
    public function actifs(array $secteurs, ?int $categorieId = null): array
    {
        $qb = $this->createQueryBuilder('j')
            ->addSelect('c')
            ->join('j.categorie', 'c')
            ->andWhere('c.secteur IN (:secteurs)')
            ->andWhere('j.ancien = false')
            ->setParameter('secteurs', $secteurs ?: ['-'])
            ->orderBy('c.ordre')
            ->addOrderBy('c.nom')
            ->addOrderBy('j.nom')
            ->addOrderBy('j.prenom');
        if ($categorieId) {
            $qb->andWhere('c.id = :cat')->setParameter('cat', $categorieId);
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * Nombre de joueurs actifs par catégorie.
     *
     * @return list<array{id: int, nom: string, secteur: string, total: int}>
     */
    public function compterActifsParCategorie(): array
    {
        return $this->createQueryBuilder('j')
            ->select('c.id, c.nom, c.secteur, COUNT(j.id) AS total')
            ->join('j.categorie', 'c')
            ->andWhere('j.ancien = false')
            ->groupBy('c.id')
            ->orderBy('c.ordre')
            ->getQuery()
            ->getArrayResult();
    }
}
