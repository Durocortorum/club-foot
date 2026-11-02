<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * Rattachement d'une donnée à son club (voir AppartientAuClub).
 */
trait ClubTrait
{
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    protected ?Club $club = null;

    public function getClub(): ?Club
    {
        return $this->club;
    }

    public function setClub(Club $club): static
    {
        $this->club = $club;

        return $this;
    }
}
