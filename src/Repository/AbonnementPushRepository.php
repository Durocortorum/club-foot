<?php

namespace App\Repository;

use App\Entity\AbonnementPush;
use App\Entity\Utilisateur;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<AbonnementPush>
 */
class AbonnementPushRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AbonnementPush::class);
    }

    /**
     * @param iterable<Utilisateur> $comptes
     *
     * @return list<AbonnementPush>
     */
    public function deComptes(iterable $comptes): array
    {
        $ids = [];
        foreach ($comptes as $c) {
            if ($c->getId()) {
                $ids[$c->getId()] = $c->getId();
            }
        }

        return $ids ? $this->createQueryBuilder('a')->andWhere('a.utilisateur IN (:ids)')->setParameter('ids', array_values($ids))->getQuery()->getResult() : [];
    }

    /**
     * Un téléphone abonné reste le même s'il change de compte. La table n'est pas filtrée par club
     * (AbonnementPush n'implémente pas AppartientAuClub) : on la lit toujours par compte ou par adresse.
     */
    public function parAdresse(string $adresse): ?AbonnementPush
    {
        return $this->findOneBy(['empreinte' => AbonnementPush::empreinteDe($adresse)]);
    }
}
