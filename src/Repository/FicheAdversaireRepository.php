<?php

namespace App\Repository;

use App\Entity\Categorie;
use App\Entity\FicheAdversaire;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<FicheAdversaire>
 */
class FicheAdversaireRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, FicheAdversaire::class);
    }

    public function pour(Categorie $categorie, string $adversaire): ?FicheAdversaire
    {
        return $this->findOneBy(['categorie' => $categorie, 'cle' => FicheAdversaire::cleDe($adversaire)]);
    }
}
