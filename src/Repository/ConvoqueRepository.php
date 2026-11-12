<?php

namespace App\Repository;

use App\Entity\Convoque;
use App\Entity\Joueur;
use App\Entity\Rencontre;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Convoque>
 */
class ConvoqueRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Convoque::class);
    }

    /**
     * Convocations envoyées à venir (match aujourd'hui ou plus tard) de ces joueurs, pour l'espace famille.
     *
     * @param iterable<Joueur> $joueurs
     *
     * @return list<Convoque>
     */
    /**
     * Convocations envoyées de ces joueurs pour les matchs entre deux dates (calendrier de l'espace famille).
     *
     * @param list<\App\Entity\Joueur> $joueurs
     *
     * @return list<\App\Entity\Convoque>
     */
    public function pourMatchs(array $joueurs, \DateTimeImmutable $debut, \DateTimeImmutable $fin): array
    {
        if (!$joueurs) {
            return [];
        }

        return $this->createQueryBuilder('cq')
            ->addSelect('c', 'r', 'j')
            ->join('cq.convocation', 'c')
            ->join('c.rencontre', 'r')
            ->join('cq.joueur', 'j')
            ->andWhere('cq.joueur IN (:joueurs)')->setParameter('joueurs', $joueurs)
            ->andWhere('c.envoyeeLe IS NOT NULL')
            ->andWhere('r.date BETWEEN :debut AND :fin')
            ->setParameter('debut', $debut, Types::DATE_IMMUTABLE)->setParameter('fin', $fin, Types::DATE_IMMUTABLE)
            ->getQuery()
            ->getResult();
    }

    public function aVenir(iterable $joueurs): array
    {
        $joueurs = [...$joueurs];
        if (!$joueurs) {
            return [];
        }

        return $this->createQueryBuilder('cq')
            ->addSelect('c', 'r')
            ->join('cq.convocation', 'c')
            ->join('c.rencontre', 'r')
            ->andWhere('cq.joueur IN (:joueurs)')->setParameter('joueurs', $joueurs)
            ->andWhere('c.envoyeeLe IS NOT NULL')
            ->andWhere('r.date >= :jour')->setParameter('jour', new \DateTimeImmutable('today'), Types::DATE_IMMUTABLE)
            ->orderBy('r.date')
            ->addOrderBy('r.heure')
            ->getQuery()
            ->getResult();
    }

    /**
     * Joueurs déjà convoqués à un autre match le même jour, ou le même week-end pour un match du samedi
     * ou du dimanche : ils ne peuvent pas être convoqués une seconde fois.
     *
     * @return array<int, Rencontre> id du joueur => l'autre match
     */
    public function dejaConvoques(Rencontre $rencontre): array
    {
        $date = $rencontre->getDate();
        if (!$date) {
            return [];
        }
        $jour = (int) $date->format('N'); // 6 samedi, 7 dimanche
        $debut = match ($jour) { 6 => $date, 7 => $date->modify('-1 day'), default => $date };
        $fin = match ($jour) { 6 => $date->modify('+1 day'), default => $date };

        $occupes = [];
        foreach ($this->createQueryBuilder('cq')
            ->addSelect('c', 'r')
            ->join('cq.convocation', 'c')
            ->join('c.rencontre', 'r')
            ->andWhere('r != :rencontre')->setParameter('rencontre', $rencontre)
            ->andWhere('r.date BETWEEN :debut AND :fin')
            ->setParameter('debut', $debut, Types::DATE_IMMUTABLE)->setParameter('fin', $fin, Types::DATE_IMMUTABLE)
            ->getQuery()->getResult() as $cq) {
            $occupes[$cq->getJoueur()->getId()] = $cq->getConvocation()->getRencontre();
        }

        return $occupes;
    }
}
