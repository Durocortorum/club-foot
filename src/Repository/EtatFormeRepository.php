<?php

namespace App\Repository;

use App\Entity\EtatForme;
use App\Entity\Joueur;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<EtatForme>
 */
class EtatFormeRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, EtatForme::class);
    }

    public function duJour(Joueur $joueur, \DateTimeImmutable $jour): ?EtatForme
    {
        return $this->findOneBy(['joueur' => $joueur, 'jour' => $jour->setTime(0, 0)]);
    }

    /**
     * États des joueurs entre deux jours, rangés par joueur puis par jour (AAAA-MM-JJ).
     *
     * @param iterable<Joueur> $joueurs
     *
     * @return array<int, array<string, EtatForme>>
     */
    public function parJoueurEtJour(iterable $joueurs, \DateTimeImmutable $debut, \DateTimeImmutable $fin): array
    {
        $joueurs = [...$joueurs];
        if (!$joueurs) {
            return [];
        }
        $liste = [];
        foreach ($this->createQueryBuilder('e')
            ->andWhere('e.joueur IN (:j)')->setParameter('j', $joueurs)
            ->andWhere('e.jour BETWEEN :d AND :f')->setParameter('d', $debut->setTime(0, 0))->setParameter('f', $fin->setTime(0, 0))
            ->getQuery()->getResult() as $e) {
            $liste[$e->getJoueur()->getId()][$e->getJour()->format('Y-m-d')] = $e;
        }

        return $liste;
    }
}
