<?php

namespace App\Entity;

use App\Repository\CreneauEntrainementRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Créneau d'entraînement récurrent d'une équipe (ex. : mercredi 14:00-15:30, stade municipal).
 * Les entraînements des semaines à venir en sont tirés automatiquement (voir PlanningEntrainements).
 */
#[ORM\Entity(repositoryClass: CreneauEntrainementRepository::class)]
class CreneauEntrainement implements AppartientAuClub
{
    use ClubTrait;

    public const JOURS = [1 => 'Lundi', 2 => 'Mardi', 3 => 'Mercredi', 4 => 'Jeudi', 5 => 'Vendredi', 6 => 'Samedi', 7 => 'Dimanche'];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    #[Assert\NotNull]
    private ?Categorie $categorie = null;

    /** Jour de la semaine, 1 (lundi) à 7 (dimanche). */
    #[ORM\Column(type: Types::SMALLINT)]
    #[Assert\Range(min: 1, max: 7)]
    private int $jour = 3;

    #[ORM\Column(length: 5)]
    #[Assert\Regex('/^([01]\d|2[0-3]):[0-5]\d$/', message: 'Heure au format 14:30.')]
    private string $heureDebut = '14:00';

    #[ORM\Column(length: 5, nullable: true)]
    #[Assert\Regex('/^([01]\d|2[0-3]):[0-5]\d$/', message: 'Heure au format 15:30.')]
    private ?string $heureFin = null;

    #[ORM\Column(length: 120, nullable: true)]
    #[Assert\Length(max: 120)]
    private ?string $lieu = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCategorie(): ?Categorie
    {
        return $this->categorie;
    }

    public function setCategorie(?Categorie $categorie): static
    {
        $this->categorie = $categorie;

        return $this;
    }

    public function getJour(): int
    {
        return $this->jour;
    }

    public function setJour(int $jour): static
    {
        $this->jour = $jour;

        return $this;
    }

    public function getJourLibelle(): string
    {
        return self::JOURS[$this->jour] ?? '?';
    }

    public function getHeureDebut(): string
    {
        return $this->heureDebut;
    }

    public function setHeureDebut(string $heureDebut): static
    {
        $this->heureDebut = $heureDebut;

        return $this;
    }

    public function getHeureFin(): ?string
    {
        return $this->heureFin;
    }

    public function setHeureFin(?string $heureFin): static
    {
        $this->heureFin = $heureFin ?: null;

        return $this;
    }

    public function getLieu(): ?string
    {
        return $this->lieu;
    }

    public function setLieu(?string $lieu): static
    {
        $this->lieu = $lieu;

        return $this;
    }

    public function __toString(): string
    {
        return $this->getJourLibelle() . ' ' . $this->heureDebut . ($this->heureFin ? '-' . $this->heureFin : '');
    }
}
