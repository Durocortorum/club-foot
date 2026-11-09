<?php

namespace App\Entity;

use App\Repository\PosteOrganigrammeRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Case de l'organigramme du club : un poste (Président, Trésorier, Responsable école de foot…),
 * la personne qui l'occupe (une fiche de l'encadrement, ou un nom saisi) et les postes placés dessous.
 */
#[ORM\Entity(repositoryClass: PosteOrganigrammeRepository::class)]
class PosteOrganigramme implements AppartientAuClub
{
    use ClubTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 80)]
    #[Assert\NotBlank(message: 'Champ obligatoire.')]
    #[Assert\Length(max: 80)]
    private ?string $titre = null;

    #[ORM\ManyToOne(targetEntity: self::class, inversedBy: 'enfants')]
    #[ORM\JoinColumn(onDelete: 'CASCADE')]
    private ?self $parent = null;

    /** @var Collection<int, self> */
    #[ORM\OneToMany(targetEntity: self::class, mappedBy: 'parent')]
    #[ORM\OrderBy(['ordre' => 'ASC', 'id' => 'ASC'])]
    private Collection $enfants;

    #[ORM\Column]
    private int $ordre = 0;

    /** Fiche de l'encadrement qui occupe le poste : type (« dirigeants », « educateurs »…) et identifiant. */
    #[ORM\Column(length: 20, nullable: true)]
    private ?string $personneType = null;

    #[ORM\Column(nullable: true)]
    private ?int $personneId = null;

    /** Nom saisi quand la personne n'a pas de fiche (ou poste à pourvoir si vide). */
    #[ORM\Column(length: 120, nullable: true)]
    #[Assert\Length(max: 120)]
    private ?string $nomLibre = null;

    public function __construct()
    {
        $this->enfants = new ArrayCollection();
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

    public function getParent(): ?self
    {
        return $this->parent;
    }

    public function setParent(?self $parent): static
    {
        $this->parent = $parent;

        return $this;
    }

    /** @return Collection<int, self> */
    public function getEnfants(): Collection
    {
        return $this->enfants;
    }

    public function getOrdre(): int
    {
        return $this->ordre;
    }

    public function setOrdre(int $ordre): static
    {
        $this->ordre = $ordre;

        return $this;
    }

    public function getPersonneType(): ?string
    {
        return $this->personneType;
    }

    public function getPersonneId(): ?int
    {
        return $this->personneId;
    }

    /** « dirigeants:12 », ou null : la personne choisie parmi les fiches de l'encadrement. */
    public function getPersonne(): ?string
    {
        return $this->personneType && $this->personneId ? $this->personneType . ':' . $this->personneId : null;
    }

    public function setPersonne(?string $cle): static
    {
        [$this->personneType, $this->personneId] = $cle && preg_match('/^([a-z]+):(\d+)$/', $cle, $m) ? [$m[1], (int) $m[2]] : [null, null];

        return $this;
    }

    public function getNomLibre(): ?string
    {
        return $this->nomLibre;
    }

    public function setNomLibre(?string $nomLibre): static
    {
        $this->nomLibre = null === $nomLibre ? null : (trim($nomLibre) ?: null);

        return $this;
    }

    /** Ce poste est-il celui-ci ou l'un de ses descendants ? (un poste ne peut pas être placé sous lui-même) */
    public function contient(self $poste): bool
    {
        for ($p = $poste; $p; $p = $p->getParent()) {
            if ($p === $this) {
                return true;
            }
        }

        return false;
    }

    public function __toString(): string
    {
        return (string) $this->titre;
    }
}
