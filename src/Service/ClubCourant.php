<?php

namespace App\Service;

use App\Entity\Club;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Club de l'utilisateur connecté. L'activer limite toutes les lectures en base à ce club (ClubFilter).
 */
class ClubCourant
{
    private ?Club $club = null;

    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    public function activer(Club $club): void
    {
        $this->club = $club;
        $this->em->getFilters()->enable('club')->setParameter('club', (int) $club->getId());
    }

    /** Plus de club courant (création d'un club pendant une requête, par exemple la démo remise à zéro). */
    public function desactiver(): void
    {
        $this->club = null;
        if ($this->em->getFilters()->isEnabled('club')) {
            $this->em->getFilters()->disable('club');
        }
    }

    public function club(): ?Club
    {
        return $this->club;
    }
}
