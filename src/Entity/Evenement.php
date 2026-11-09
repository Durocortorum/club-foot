<?php

namespace App\Entity;

use App\Repository\EvenementRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Événement extra-sportif du club (fête, repas, assemblée générale, sortie…) : quoi, quand, où, pour qui.
 * Sans équipe choisie, il concerne tout le club.
 */
#[ORM\Entity(repositoryClass: EvenementRepository::class)]
class Evenement implements AppartientAuClub
{
    use ClubTrait;

    public const TYPES = ['Fête du club', 'Repas / soirée', 'Assemblée générale', 'Réunion', 'Sortie', 'Loto / tombola', 'Cérémonie', 'Autre'];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 120)]
    #[Assert\NotBlank(message: 'Champ obligatoire.')]
    #[Assert\Length(max: 120)]
    private ?string $titre = null;

    #[ORM\Column(length: 30)]
    #[Assert\Choice(choices: self::TYPES)]
    private string $type = 'Autre';

    #[ORM\Column]
    #[Assert\NotNull(message: 'Champ obligatoire.')]
    private ?\DateTimeImmutable $debut = null;

    #[ORM\Column(nullable: true)]
    #[Assert\GreaterThanOrEqual(propertyPath: 'debut', message: 'La fin doit être après le début.')]
    private ?\DateTimeImmutable $fin = null;

    #[ORM\Column(length: 150, nullable: true)]
    #[Assert\Length(max: 150)]
    private ?string $lieu = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $description = null;

    /** Équipes concernées ; aucune = tout le club. */
    #[ORM\ManyToMany(targetEntity: Categorie::class)]
    #[ORM\OrderBy(['ordre' => 'ASC'])]
    private Collection $categories;

    /** Affiché dans l'espace des familles (sinon réservé à l'encadrement, une réunion par exemple). */
    #[ORM\Column]
    private bool $visibleFamilles = true;

    public function __construct()
    {
        $this->categories = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTitre(): ?string
    {
        return $this->titre;
    }

    public function setTitre(?string $titre): static
    {
        $this->titre = null === $titre ? null : trim($titre);

        return $this;
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function setType(?string $type): static
    {
        $this->type = $type ?: 'Autre';

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

    public function getLieu(): ?string
    {
        return $this->lieu;
    }

    public function setLieu(?string $lieu): static
    {
        $this->lieu = $lieu;

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

    /** @return Collection<int, Categorie> */
    public function getCategories(): Collection
    {
        return $this->categories;
    }

    public function addCategory(Categorie $categorie): static
    {
        if (!$this->categories->contains($categorie)) {
            $this->categories->add($categorie);
        }

        return $this;
    }

    public function removeCategory(Categorie $categorie): static
    {
        $this->categories->removeElement($categorie);

        return $this;
    }

    public function isToutLeClub(): bool
    {
        return $this->categories->isEmpty();
    }

    /** L'événement concerne-t-il l'une de ces équipes (ou tout le club) ? */
    public function concerne(iterable $categories): bool
    {
        if ($this->isToutLeClub()) {
            return true;
        }
        foreach ($categories as $c) {
            if ($this->categories->contains($c)) {
                return true;
            }
        }

        return false;
    }

    public function isVisibleFamilles(): bool
    {
        return $this->visibleFamilles;
    }

    public function setVisibleFamilles(bool $visibleFamilles): static
    {
        $this->visibleFamilles = $visibleFamilles;

        return $this;
    }

    /** Dernier jour de l'événement (le jour du début s'il n'a pas de fin). */
    public function getDernierJour(): \DateTimeImmutable
    {
        return ($this->fin ?? $this->debut)->setTime(0, 0);
    }

    public function __toString(): string
    {
        return (string) $this->titre;
    }
}
