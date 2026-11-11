<?php

namespace App\Repository;

use App\Entity\Conducteur;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Conducteur>
 */
class ConducteurRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Conducteur::class);
    }

    /** @return list<Conducteur> conducteurs dont le permis est à contrôler (ou plus valable) */
    public function aControler(): array
    {
        return array_values(array_filter($this->findBy([], ['nom' => 'ASC']), fn (Conducteur $c) => !$c->isEnRegle()));
    }

    /** Conducteur de ce nom (espaces et majuscules ignorés), s'il est dans la liste. */
    public function parNom(?string $nom): ?Conducteur
    {
        $cle = mb_strtolower(trim(preg_replace('/\s+/u', ' ', (string) $nom) ?? ''));
        foreach ($cle ? $this->findAll() : [] as $c) {
            if (mb_strtolower((string) $c->getNom()) === $cle) {
                return $c;
            }
        }

        return null;
    }
}
