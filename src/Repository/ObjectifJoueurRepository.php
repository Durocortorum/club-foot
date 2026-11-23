<?php

namespace App\Repository;

use App\Entity\Joueur;
use App\Entity\ObjectifJoueur;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ObjectifJoueur>
 */
class ObjectifJoueurRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ObjectifJoueur::class);
    }

    /** @return list<ObjectifJoueur> objectifs du joueur pour la saison, dans l'ordre de création */
    public function duJoueur(Joueur $joueur, string $saison): array
    {
        return $this->findBy(['joueur' => $joueur, 'saison' => $saison], ['id' => 'ASC']);
    }

    /** @return list<string> saisons où le joueur a des objectifs, la plus récente d'abord */
    public function saisons(Joueur $joueur): array
    {
        $saisons = array_column($this->createQueryBuilder('o')->select('DISTINCT o.saison')
            ->andWhere('o.joueur = :j')->setParameter('j', $joueur)->getQuery()->getArrayResult(), 'saison');
        rsort($saisons);

        return $saisons;
    }
}
