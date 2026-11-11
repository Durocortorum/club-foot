<?php

namespace App\Entity;

use App\Repository\OperationRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Recette ou dépense du club (hors cotisations, suivies à part) : entrées d'un match (nombre d'entrées
 * × prix de la place), buvette, achats de matériel, arbitrage, déplacements, subventions…
 * Un justificatif (facture, ticket) peut être joint.
 */
#[ORM\Entity(repositoryClass: OperationRepository::class)]
#[Assert\Expression('this.getMontant() !== null or (this.getNbEntrees() and this.getPrixPlace() !== null)', message: 'Indiquez le montant (ou le nombre d\'entrées et le prix de la place).')]
class Operation implements AppartientAuClub
{
    use ClubTrait;

    public const RECETTE = 'recette';
    public const DEPENSE = 'depense';

    /** Rubriques proposées (la liste reste ouverte). */
    public const RUBRIQUES_RECETTES = ['Entrées des matchs', 'Buvette', 'Subventions', 'Sponsoring', 'Tournois / manifestations', 'Stages', 'Dons', 'Autre recette'];
    public const RUBRIQUES_DEPENSES = ['Matériel', 'Équipements / maillots', 'Arbitrage', 'Déplacements', 'Licences / engagements', 'Location des installations', 'Assurance', 'Salaires / indemnités', 'Buvette (achats)', 'Autre dépense'];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 10)]
    #[Assert\Choice(choices: [self::RECETTE, self::DEPENSE])]
    private string $sens = self::DEPENSE;

    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    #[Assert\NotNull(message: 'Indiquez la date.')]
    private ?\DateTimeImmutable $date = null;

    #[ORM\Column(length: 60)]
    #[Assert\NotBlank(message: 'Choisissez une rubrique.')]
    private ?string $rubrique = null;

    #[ORM\Column(length: 255)]
    #[Assert\NotBlank(message: 'Indiquez le libellé.')]
    private ?string $libelle = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 2)]
    #[Assert\PositiveOrZero(message: 'Le montant ne peut pas être négatif.')]
    private ?string $montant = null;

    /** Entrées d'un match : nombre d'entrées payantes et prix de la place (le montant en découle). */
    #[ORM\Column(nullable: true)]
    #[Assert\PositiveOrZero]
    private ?int $nbEntrees = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 8, scale: 2, nullable: true)]
    #[Assert\PositiveOrZero]
    private ?string $prixPlace = null;

    #[ORM\Column(length: 30, nullable: true)]
    private ?string $mode = null;

    /** Fournisseur, organisme ou personne. */
    #[ORM\Column(length: 120, nullable: true)]
    private ?string $tiers = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $note = null;

    /** Justificatif joint (PDF ou photo), rangé par le service Documents. */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $fichier = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getSens(): string
    {
        return $this->sens;
    }

    public function setSens(string $sens): static
    {
        $this->sens = $sens;

        return $this;
    }

    public function isRecette(): bool
    {
        return self::RECETTE === $this->sens;
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

    public function getRubrique(): ?string
    {
        return $this->rubrique;
    }

    public function setRubrique(?string $rubrique): static
    {
        $this->rubrique = $rubrique ? trim($rubrique) : null;

        return $this;
    }

    public function getLibelle(): ?string
    {
        return $this->libelle;
    }

    public function setLibelle(?string $libelle): static
    {
        $this->libelle = $libelle;

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

    public function getNbEntrees(): ?int
    {
        return $this->nbEntrees;
    }

    public function setNbEntrees(?int $nbEntrees): static
    {
        $this->nbEntrees = $nbEntrees;

        return $this;
    }

    public function getPrixPlace(): ?float
    {
        return null === $this->prixPlace ? null : (float) $this->prixPlace;
    }

    public function setPrixPlace(?float $prixPlace): static
    {
        $this->prixPlace = null === $prixPlace ? null : number_format($prixPlace, 2, '.', '');

        return $this;
    }

    /** Entrées d'un match : le montant est le nombre d'entrées × le prix de la place. */
    public function calculerMontant(): static
    {
        if ($this->nbEntrees && null !== $this->prixPlace) {
            $this->setMontant($this->nbEntrees * (float) $this->prixPlace);
        }

        return $this;
    }

    public function getMode(): ?string
    {
        return $this->mode;
    }

    public function setMode(?string $mode): static
    {
        $this->mode = $mode ?: null;

        return $this;
    }

    public function getTiers(): ?string
    {
        return $this->tiers;
    }

    public function setTiers(?string $tiers): static
    {
        $this->tiers = $tiers ?: null;

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
}
