<?php

namespace App\Repository;

use App\Entity\Categorie;
use App\Entity\Rencontre;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\QueryBuilder;
use Doctrine\ORM\Tools\Pagination\Paginator;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Rencontre>
 */
class RencontreRepository extends ServiceEntityRepository
{
    public const PAR_PAGE = 30;

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Rencontre::class);
    }

    /**
     * @param list<string>|null $secteurs secteurs autorisés, null pour tous
     */
    public function rechercher(?array $secteurs, ?string $saison, ?int $categorieId, ?string $texte, int $page): Paginator
    {
        $qb = $this->filtrer($this->createQueryBuilder('r')->addSelect('c'), $secteurs, $saison, $categorieId)
            ->orderBy('r.date', 'DESC')
            ->addOrderBy('c.ordre')
            ->setFirstResult(($page - 1) * self::PAR_PAGE)
            ->setMaxResults(self::PAR_PAGE);

        if ($texte) {
            $qb->andWhere('r.domicile LIKE :t OR r.exterieur LIKE :t OR r.lieu LIKE :t')
                ->setParameter('t', '%' . $texte . '%');
        }

        return new Paginator($qb);
    }

    /**
     * Prochains matchs d'une catégorie, à partir d'aujourd'hui (espace famille).
     *
     * @return list<Rencontre>
     */
    public function aVenir(Categorie $categorie, int $nombre): array
    {
        return $this->createQueryBuilder('r')
            ->andWhere('r.categorie = :c')->setParameter('c', $categorie)
            ->andWhere('r.date >= :jour')->setParameter('jour', new \DateTimeImmutable('today'), Types::DATE_IMMUTABLE)
            ->orderBy('r.date')
            ->addOrderBy('r.heure')
            ->setMaxResults($nombre)
            ->getQuery()
            ->getResult();
    }

    /**
     * Saisons pour lesquelles des matchs existent, de la plus récente à la plus ancienne.
     *
     * @return list<string>
     */
    public function saisons(): array
    {
        $bornes = $this->createQueryBuilder('r')
            ->select('MIN(r.date) AS debut, MAX(r.date) AS fin')
            ->getQuery()
            ->getSingleResult();

        return self::saisonsEntre($bornes['debut'], $bornes['fin']);
    }

    /**
     * Saisons où chaque catégorie a joué des matchs (écran « Archives »).
     *
     * @param list<string>|null $secteurs
     *
     * @return array<int, list<string>> id de la catégorie => saisons, de la plus ancienne à la plus récente
     */
    public function saisonsParCategorie(?array $secteurs): array
    {
        $lignes = $this->filtrer($this->createQueryBuilder('r')->select('c.id AS categorie, r.date'), $secteurs, null, null)
            ->andWhere('c.id IS NOT NULL')
            ->andWhere('r.date IS NOT NULL')
            ->getQuery()
            ->getArrayResult();

        return self::regrouperParSaison($lignes, 'date');
    }

    /**
     * @param list<array{categorie: int}> $lignes
     *
     * @return array<int, list<string>>
     */
    public static function regrouperParSaison(array $lignes, string $champDate): array
    {
        $saisons = [];
        foreach ($lignes as $l) {
            $saisons[$l['categorie']][Rencontre::saisonDe($l[$champDate])] = true;
        }
        foreach ($saisons as &$s) {
            $s = array_keys($s);
            sort($s);
        }

        return $saisons;
    }

    /**
     * Date du dernier match pour ces filtres.
     *
     * @param list<string>|null $secteurs
     */
    public function dernierMatch(?array $secteurs, ?string $saison, ?int $categorieId): ?\DateTimeImmutable
    {
        $date = $this->filtrer($this->createQueryBuilder('r')->select('MAX(r.date)'), $secteurs, $saison, $categorieId)
            ->getQuery()->getSingleScalarResult();

        return $date ? new \DateTimeImmutable($date) : null;
    }

    /** Saison du match le plus récent, ou la saison en cours s'il n'y a aucun match. */
    public function derniereSaison(): string
    {
        $fin = $this->createQueryBuilder('r')->select('MAX(r.date)')->getQuery()->getSingleScalarResult();

        return Rencontre::saisonDe(new \DateTimeImmutable($fin ?? 'now'));
    }

    /** @return list<string> */
    public static function saisonsEntre(?string $debut, ?string $fin): array
    {
        $courante = Rencontre::saisonDe(new \DateTimeImmutable());
        if (!$debut) {
            return [$courante];
        }
        $premiere = (int) substr(Rencontre::saisonDe(new \DateTimeImmutable($debut)), 0, 4);
        $derniere = max((int) substr($courante, 0, 4), (int) substr(Rencontre::saisonDe(new \DateTimeImmutable($fin)), 0, 4));

        $saisons = [];
        for ($a = $derniere; $a >= $premiere; --$a) {
            $saisons[] = $a . '/' . ($a + 1);
        }

        return $saisons;
    }

    /**
     * Restreint une requête dont l'alias de rencontre est « r » ; joint la catégorie sous l'alias « c ».
     *
     * @param list<string>|null $secteurs
     */
    public function filtrer(QueryBuilder $qb, ?array $secteurs, ?string $saison, ?int $categorieId): QueryBuilder
    {
        $qb->leftJoin('r.categorie', 'c');

        if (null !== $secteurs) {
            $qb->andWhere('c.secteur IN (:secteurs)')->setParameter('secteurs', $secteurs ?: ['-']);
        }
        if ($saison) {
            [$debut, $fin] = Rencontre::bornesSaison($saison);
            $qb->andWhere('r.date BETWEEN :debut AND :fin')
                ->setParameter('debut', $debut->format('Y-m-d'))
                ->setParameter('fin', $fin->format('Y-m-d'));
        }
        if ($categorieId) {
            $qb->andWhere('c.id = :cat')->setParameter('cat', $categorieId);
        }

        return $qb;
    }

    /**
     * Bilan victoires / nuls / défaites et buts, pour les mêmes filtres que la liste.
     *
     * @param list<string>|null $secteurs
     *
     * @return array{matchs: int, V: int, N: int, D: int, pour: int, contre: int}
     */
    public function bilan(?array $secteurs, ?string $saison, ?int $categorieId): array
    {
        $bilan = ['matchs' => 0, 'V' => 0, 'N' => 0, 'D' => 0, 'pour' => 0, 'contre' => 0];
        $rencontres = $this->filtrer($this->createQueryBuilder('r'), $secteurs, $saison, $categorieId)->getQuery()->toIterable();
        foreach ($rencontres as $r) {
            if ($resultat = $r->getResultat()) {
                ++$bilan['matchs'];
                ++$bilan[$resultat];
                $bilan['pour'] += $r->getButsPour();
                $bilan['contre'] += $r->getButsContre();
            }
        }

        return $bilan;
    }
}
