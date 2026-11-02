<?php

namespace App\Repository;

use App\Entity\Utilisateur;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Utilisateur>
 */
class UtilisateurRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Utilisateur::class);
    }

    /**
     * Comptes ayant ces critères dans tous les clubs : l'adresse e-mail sert à se connecter,
     * elle doit donc être unique dans toute l'application (voir UniqueEntity sur Utilisateur).
     *
     * @param array<string, mixed> $criteres
     *
     * @return list<Utilisateur>
     */
    public function tousClubs(array $criteres): array
    {
        $filtres = $this->getEntityManager()->getFilters();
        if (!$filtres->isEnabled('club')) {
            return $this->findBy($criteres);
        }
        $filtres->suspend('club');
        try {
            return $this->findBy($criteres);
        } finally {
            $filtres->restore('club');
        }
    }
}
