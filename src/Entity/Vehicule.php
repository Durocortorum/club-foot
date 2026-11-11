<?php

namespace App\Entity;

use App\Repository\VehiculeRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Véhicule du club (minibus…) : places, kilométrage, cartes carburant et autoroute,
 * échéances (assurance, contrôle technique, révision) signalées à l'avance.
 */
#[ORM\Entity(repositoryClass: VehiculeRepository::class)]
class Vehicule implements AppartientAuClub
{
    use ClubTrait;

    /** Nombre de jours avant une échéance à partir duquel elle est signalée. */
    public const ALERTE_JOURS = 30;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 80)]
    #[Assert\NotBlank(message: 'Indiquez le nom du véhicule.')]
    private ?string $nom = null;

    #[ORM\Column(length: 20, nullable: true)]
    private ?string $immatriculation = null;

    #[ORM\Column(type: Types::SMALLINT, nullable: true)]
    #[Assert\Range(min: 1, max: 99)]
    private ?int $places = null;

    #[ORM\Column(nullable: true)]
    #[Assert\PositiveOrZero]
    private ?int $kilometrage = null;

    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $assurance = null;

    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $controleTechnique = null;

    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $revision = null;

    #[ORM\Column(length: 60, nullable: true)]
    private ?string $carteCarburant = null;

    #[ORM\Column(length: 60, nullable: true)]
    private ?string $badgeAutoroute = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $note = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getNom(): ?string
    {
        return $this->nom;
    }

    public function setNom(?string $nom): static
    {
        $this->nom = $nom;

        return $this;
    }

    public function getImmatriculation(): ?string
    {
        return $this->immatriculation;
    }

    public function setImmatriculation(?string $immatriculation): static
    {
        $this->immatriculation = $immatriculation ? mb_strtoupper(trim($immatriculation)) : null;

        return $this;
    }

    public function getPlaces(): ?int
    {
        return $this->places;
    }

    public function setPlaces(?int $places): static
    {
        $this->places = $places;

        return $this;
    }

    public function getKilometrage(): ?int
    {
        return $this->kilometrage;
    }

    public function setKilometrage(?int $kilometrage): static
    {
        $this->kilometrage = $kilometrage;

        return $this;
    }

    public function getAssurance(): ?\DateTimeImmutable
    {
        return $this->assurance;
    }

    public function setAssurance(?\DateTimeImmutable $assurance): static
    {
        $this->assurance = $assurance;

        return $this;
    }

    public function getControleTechnique(): ?\DateTimeImmutable
    {
        return $this->controleTechnique;
    }

    public function setControleTechnique(?\DateTimeImmutable $controleTechnique): static
    {
        $this->controleTechnique = $controleTechnique;

        return $this;
    }

    public function getRevision(): ?\DateTimeImmutable
    {
        return $this->revision;
    }

    public function setRevision(?\DateTimeImmutable $revision): static
    {
        $this->revision = $revision;

        return $this;
    }

    public function getCarteCarburant(): ?string
    {
        return $this->carteCarburant;
    }

    public function setCarteCarburant(?string $carteCarburant): static
    {
        $this->carteCarburant = $carteCarburant ?: null;

        return $this;
    }

    public function getBadgeAutoroute(): ?string
    {
        return $this->badgeAutoroute;
    }

    public function setBadgeAutoroute(?string $badgeAutoroute): static
    {
        $this->badgeAutoroute = $badgeAutoroute ?: null;

        return $this;
    }

    public function getNote(): ?string
    {
        return $this->note;
    }

    public function setNote(?string $note): static
    {
        $this->note = $note ?: null;

        return $this;
    }

    /**
     * Échéances dépassées ou proches (moins de ALERTE_JOURS jours).
     *
     * @return list<array{libelle: string, date: \DateTimeImmutable, depassee: bool}>
     */
    public function getAlertes(): array
    {
        $aujourdhui = new \DateTimeImmutable('today');
        $limite = $aujourdhui->modify('+' . self::ALERTE_JOURS . ' days');
        $alertes = [];
        foreach (['Assurance' => $this->assurance, 'Contrôle technique' => $this->controleTechnique, 'Révision' => $this->revision] as $libelle => $date) {
            if ($date && $date <= $limite) {
                $alertes[] = ['libelle' => $libelle, 'date' => $date, 'depassee' => $date < $aujourdhui];
            }
        }

        return $alertes;
    }

    public function __toString(): string
    {
        return $this->nom . ($this->immatriculation ? ' (' . $this->immatriculation . ')' : '');
    }
}
