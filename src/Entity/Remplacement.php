<?php

namespace App\Entity;

use App\Repository\RemplacementRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Changement de joueur (table Access « Tbl remplacant2 »). Chez les jeunes,
 * un joueur sorti peut revenir plus tard dans la rencontre.
 */
#[ORM\Entity(repositoryClass: RemplacementRepository::class)]
class Remplacement implements AppartientAuClub
{
    use ClubTrait;

    public const MOTIFS = ['Choix', 'Blessure'];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'remplacements')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Rencontre $rencontre = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?Joueur $sortant = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?Joueur $entrant = null;

    #[ORM\Column(nullable: true)]
    #[Assert\Range(min: 0, max: 130)]
    private ?int $minute = null;

    #[ORM\Column(length: 20, nullable: true)]
    private ?string $motif = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getRencontre(): ?Rencontre
    {
        return $this->rencontre;
    }

    public function setRencontre(?Rencontre $rencontre): static
    {
        $this->rencontre = $rencontre;

        return $this;
    }

    public function getSortant(): ?Joueur
    {
        return $this->sortant;
    }

    public function setSortant(?Joueur $sortant): static
    {
        $this->sortant = $sortant;

        return $this;
    }

    public function getEntrant(): ?Joueur
    {
        return $this->entrant;
    }

    public function setEntrant(?Joueur $entrant): static
    {
        $this->entrant = $entrant;

        return $this;
    }

    public function getMinute(): ?int
    {
        return $this->minute;
    }

    public function setMinute(?int $minute): static
    {
        $this->minute = $minute;

        return $this;
    }

    public function getMotif(): ?string
    {
        return $this->motif;
    }

    public function setMotif(?string $motif): static
    {
        $this->motif = $motif;

        return $this;
    }
}
