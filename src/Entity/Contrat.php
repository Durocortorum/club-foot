<?php

namespace App\Entity;

use App\Repository\ContratRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Contrat du club (joueur, éducateur, fournisseur, location, assurance…) : dates, montant, PDF signé.
 * Une alerte signale ceux qui arrivent à échéance.
 */
#[ORM\Entity(repositoryClass: ContratRepository::class)]
#[Assert\Expression('!this.getDebut() or !this.getFin() or this.getFin() >= this.getDebut()', message: 'La fin ne peut pas précéder le début.')]
class Contrat implements AppartientAuClub
{
    use ClubTrait;

    public const TYPES = ['Joueur', 'Éducateur', 'Salarié', 'Fournisseur', 'Location / installations', 'Assurance', 'Transport', 'Sponsoring', 'Autre'];
    public const PERIODICITES = ['Une fois', 'Par mois', 'Par trimestre', 'Par an'];
    /** Nombre de jours avant la fin à partir duquel le contrat est signalé. */
    public const ALERTE_JOURS = 60;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 150)]
    #[Assert\NotBlank(message: 'Indiquez l\'objet du contrat.')]
    private ?string $objet = null;

    #[ORM\Column(length: 40)]
    #[Assert\NotBlank(message: 'Choisissez le type.')]
    private ?string $type = null;

    /** Avec qui : personne, entreprise, organisme. */
    #[ORM\Column(length: 150)]
    #[Assert\NotBlank(message: 'Indiquez avec qui le contrat est signé.')]
    private ?string $partie = null;

    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $debut = null;

    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $fin = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 2, nullable: true)]
    #[Assert\PositiveOrZero(message: 'Le montant ne peut pas être négatif.')]
    private ?string $montant = null;

    #[ORM\Column(length: 20, nullable: true)]
    private ?string $periodicite = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $note = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $fichier = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getObjet(): ?string
    {
        return $this->objet;
    }

    public function setObjet(?string $objet): static
    {
        $this->objet = $objet;

        return $this;
    }

    public function getType(): ?string
    {
        return $this->type;
    }

    public function setType(?string $type): static
    {
        $this->type = $type ? trim($type) : null;

        return $this;
    }

    public function getPartie(): ?string
    {
        return $this->partie;
    }

    public function setPartie(?string $partie): static
    {
        $this->partie = $partie;

        return $this;
    }

    public function getDebut(): ?\DateTimeImmutable
    {
        return $this->debut;
    }

    public function setDebut(?\DateTimeImmutable $debut): static
    {
        $this->debut = $debut;

        return $this;
    }

    public function getFin(): ?\DateTimeImmutable
    {
        return $this->fin;
    }

    public function setFin(?\DateTimeImmutable $fin): static
    {
        $this->fin = $fin;

        return $this;
    }

    public function getMontant(): ?float
    {
        return null === $this->montant ? null : (float) $this->montant;
    }

    public function setMontant(?float $montant): static
    {
        $this->montant = null === $montant ? null : number_format($montant, 2, '.', '');

        return $this;
    }

    public function getPeriodicite(): ?string
    {
        return $this->periodicite;
    }

    public function setPeriodicite(?string $periodicite): static
    {
        $this->periodicite = $periodicite ?: null;

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

    public function getFichier(): ?string
    {
        return $this->fichier;
    }

    public function setFichier(?string $fichier): static
    {
        $this->fichier = $fichier;

        return $this;
    }

    /** « en_cours », « bientot » (fin dans moins de ALERTE_JOURS jours), « termine » ou « a_venir ». */
    public function getEtat(): string
    {
        $aujourdhui = new \DateTimeImmutable('today');

        return match (true) {
            null !== $this->debut && $this->debut > $aujourdhui => 'a_venir',
            null !== $this->fin && $this->fin < $aujourdhui => 'termine',
            null !== $this->fin && $this->fin <= $aujourdhui->modify('+' . self::ALERTE_JOURS . ' days') => 'bientot',
            default => 'en_cours',
        };
    }

    /** Jours restants avant la fin (négatif si terminé), null sans date de fin. */
    public function getJoursRestants(): ?int
    {
        return $this->fin ? (int) (new \DateTimeImmutable('today'))->diff($this->fin)->format('%r%a') : null;
    }
}
