<?php

namespace App\Entity;

use App\Repository\TestPhysiqueRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Tests de vitesse, de détente et de VMA (table Access « Tbl TestVitesseVma »).
 */
#[ORM\Entity(repositoryClass: TestPhysiqueRepository::class)]
class TestPhysique implements AppartientAuClub
{
    use ClubTrait;

    /** Épreuves : champ => [libellé, unité]. */
    public const EPREUVES = [
        'sprint10' => ['10 m', 's'],
        'sprint20' => ['20 m', 's'],
        'sprint40' => ['40 m', 's'],
        'navette4x10' => ['4 × 10 m', 's'],
        'vma' => ['VMA', 'km/h'],
        'detente' => ['Détente verticale', 'cm'],
        'cinqFoulees' => ['5 foulées', 'm'],
        'frequence20' => ['20 m fréquence', 's'],
        'amplitude20' => ['20 m amplitude', 's'],
    ];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Joueur $joueur = null;

    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    #[Assert\NotNull]
    private ?\DateTimeImmutable $date = null;

    #[ORM\Column(nullable: true)]
    #[Assert\Positive]
    private ?float $sprint10 = null;

    #[ORM\Column(nullable: true)]
    #[Assert\Positive]
    private ?float $sprint20 = null;

    #[ORM\Column(nullable: true)]
    #[Assert\Positive]
    private ?float $sprint40 = null;

    #[ORM\Column(nullable: true)]
    #[Assert\Positive]
    private ?float $navette4x10 = null;

    #[ORM\Column(nullable: true)]
    #[Assert\Positive]
    private ?float $vma = null;

    #[ORM\Column(nullable: true)]
    #[Assert\Positive]
    private ?float $detente = null;

    #[ORM\Column(nullable: true)]
    #[Assert\Positive]
    private ?float $cinqFoulees = null;

    #[ORM\Column(nullable: true)]
    #[Assert\Positive]
    private ?float $frequence20 = null;

    #[ORM\Column(nullable: true)]
    #[Assert\Positive]
    private ?float $amplitude20 = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getJoueur(): ?Joueur
    {
        return $this->joueur;
    }

    public function setJoueur(?Joueur $joueur): static
    {
        $this->joueur = $joueur;

        return $this;
    }

    public function getDate(): ?\DateTimeImmutable
    {
        return $this->date;
    }

    public function setDate(?\DateTimeImmutable $date): static
    {
        $this->date = $date;

        return $this;
    }

    public function getSprint10(): ?float
    {
        return $this->sprint10;
    }

    public function setSprint10(?float $sprint10): static
    {
        $this->sprint10 = $sprint10;

        return $this;
    }

    public function getSprint20(): ?float
    {
        return $this->sprint20;
    }

    public function setSprint20(?float $sprint20): static
    {
        $this->sprint20 = $sprint20;

        return $this;
    }

    public function getSprint40(): ?float
    {
        return $this->sprint40;
    }

    public function setSprint40(?float $sprint40): static
    {
        $this->sprint40 = $sprint40;

        return $this;
    }

    public function getNavette4x10(): ?float
    {
        return $this->navette4x10;
    }

    public function setNavette4x10(?float $navette4x10): static
    {
        $this->navette4x10 = $navette4x10;

        return $this;
    }

    public function getVma(): ?float
    {
        return $this->vma;
    }

    public function setVma(?float $vma): static
    {
        $this->vma = $vma;

        return $this;
    }

    public function getDetente(): ?float
    {
        return $this->detente;
    }

    public function setDetente(?float $detente): static
    {
        $this->detente = $detente;

        return $this;
    }

    public function getCinqFoulees(): ?float
    {
        return $this->cinqFoulees;
    }

    public function setCinqFoulees(?float $cinqFoulees): static
    {
        $this->cinqFoulees = $cinqFoulees;

        return $this;
    }

    public function getFrequence20(): ?float
    {
        return $this->frequence20;
    }

    public function setFrequence20(?float $frequence20): static
    {
        $this->frequence20 = $frequence20;

        return $this;
    }

    public function getAmplitude20(): ?float
    {
        return $this->amplitude20;
    }

    public function setAmplitude20(?float $amplitude20): static
    {
        $this->amplitude20 = $amplitude20;

        return $this;
    }
}
