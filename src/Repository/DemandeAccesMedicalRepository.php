<?php

namespace App\Repository;

use App\Entity\DemandeAccesMedical;
use App\Entity\Utilisateur;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<DemandeAccesMedical>
 */
class DemandeAccesMedicalRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, DemandeAccesMedical::class);
    }

    /** @return list<DemandeAccesMedical> demandes du club en attente, les plus anciennes d'abord */
    public function enAttente(): array
    {
        return $this->findBy(['decision' => null], ['demandeeLe' => 'ASC']);
    }

    public function nombreEnAttente(): int
    {
        return $this->count(['decision' => null]);
    }

    /** Demande en attente de ce compte pour cette section, s'il y en a une. */
    public function enAttentePour(Utilisateur $demandeur, string $section): ?DemandeAccesMedical
    {
        return $this->findOneBy(['demandeur' => $demandeur, 'section' => $section, 'decision' => null]);
    }

    /** @return list<DemandeAccesMedical> dernières demandes de ce compte */
    public function dernieresDe(Utilisateur $demandeur, int $nombre = 5): array
    {
        return $this->findBy(['demandeur' => $demandeur], ['demandeeLe' => 'DESC'], $nombre);
    }
}
