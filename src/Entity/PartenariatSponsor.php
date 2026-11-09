<?php

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Participation d'un sponsor pour une saison : nature (argent, maillots, panneau…), montant, contrat.
 */
#[ORM\Entity]
class PartenariatSponsor implements AppartientAuClub
{
    use ClubTrait;

    public const NATURES = ['Argent', 'Maillots / équipements', 'Matériel', 'Panneau publicitaire', 'Transport', 'Lots / dons', 'Prestations', 'Autre'];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'partenariats')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Sponsor $sponsor = null;

    /** Saison au format « 2026/2027 ». */
    #[ORM\Column(length: 9)]
    #[Assert\Regex('#^\d{4}/\d{4}$#', message: 'Saison au format 2026/2027.')]
    private ?string $saison = null;

    #[ORM\Column(length: 40)]
    #[Assert\Choice(choices: self::NATURES)]
    private string $nature = 'Argent';

    /** Montant versé, ou valeur estimée pour une participation en nature. */
    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 2, nullable: true)]
    #[Assert\PositiveOrZero(message: 'Le montant ne peut pas être négatif.')]
    private ?string $montant = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $description = null;

    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $contratDebut = null;

    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    #[Assert\GreaterThanOrEqual(propertyPath: 'contratDebut', message: 'La fin du contrat doit être après son début.')]
    private ?\DateTimeImmutable $contratFin = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getSponsor(): ?Sponsor
    {
        return $this->sponsor;
    }

    public function setSponsor(Sponsor $sponsor): static
    {
        $this->sponsor = $sponsor;

        return $this;
    }

    public function getSaison(): ?string
    {
        return $this->saison;
    }

    public function setSaison(?string $saison): static
    {
        $this->saison = $saison;

        return $this;
    }

    public function getNature(): string
    {
        return $this->nature;
    }

    public function setNature(?string $nature): static
    {
        $this->nature = $nature ?: 'Argent';

        return $this;
    }

    public function getMontant(): ?string
    {
        return $this->montant;
    }

    public function setMontant(?string $montant): static
    {
        $this->montant = $montant;

        return $this;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): static
    {
        $this->description = $description;

        return $this;
    }

    public function getContratDebut(): ?\DateTimeImmutable
    {
        return $this->contratDebut;
    }

    public function setContratDebut(?\DateTimeImmutable $contratDebut): static
    {
        $this->contratDebut = $contratDebut;

        return $this;
    }

    public function getContratFin(): ?\DateTimeImmutable
    {
        return $this->contratFin;
    }

    public function setContratFin(?\DateTimeImmutable $contratFin): static
    {
        $this->contratFin = $contratFin;

        return $this;
    }
}
