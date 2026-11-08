<?php

namespace App\Entity;

use App\Repository\ProcheRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Personne à contacter pour un joueur majeur (parent, conjoint, proche...). Le joueur les gère
 * dans son espace ; le club les voit sur sa fiche. Une personne avec un e-mail peut recevoir
 * l'accès à l'espace du joueur (compte famille), si le joueur le décide.
 */
#[ORM\Entity(repositoryClass: ProcheRepository::class)]
class Proche implements AppartientAuClub
{
    use ClubTrait;

    /** Suggestions pour le lien avec le joueur. */
    public const LIENS = ['Père', 'Mère', 'Parent', 'Conjoint(e)', 'Frère / sœur', 'Ami(e)', 'Tuteur'];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Joueur $joueur = null;

    #[ORM\Column(length: 120)]
    #[Assert\NotBlank(message: 'Indiquez le nom de la personne.')]
    #[Assert\Length(max: 120)]
    private ?string $nom = null;

    /** Lien avec le joueur : père, conjoint(e)... */
    #[ORM\Column(length: 60, nullable: true)]
    #[Assert\Length(max: 60)]
    private ?string $lien = null;

    #[ORM\Column(length: 30, nullable: true)]
    #[Assert\Length(max: 30)]
    private ?string $telephone = null;

    #[ORM\Column(length: 180, nullable: true)]
    #[Assert\Email(message: 'Adresse e-mail invalide.')]
    #[Assert\Length(max: 180)]
    private ?string $email = null;

    /** Le joueur autorise cette personne (si elle a accès à son espace) à voir ses statistiques. */
    #[ORM\Column(options: ['default' => false])]
    private bool $voitStatistiques = false;

    public function isVoitStatistiques(): bool
    {
        return $this->voitStatistiques;
    }

    public function setVoitStatistiques(bool $voit): static
    {
        $this->voitStatistiques = $voit;

        return $this;
    }

    public function __construct(?Joueur $joueur = null)
    {
        $this->joueur = $joueur;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getJoueur(): ?Joueur
    {
        return $this->joueur;
    }

    public function getNom(): ?string
    {
        return $this->nom;
    }

    public function setNom(?string $nom): static
    {
        $this->nom = null === $nom ? null : trim($nom);

        return $this;
    }

    public function getLien(): ?string
    {
        return $this->lien;
    }

    public function setLien(?string $lien): static
    {
        $this->lien = $lien ? trim($lien) : null;

        return $this;
    }

    public function getTelephone(): ?string
    {
        return $this->telephone;
    }

    public function setTelephone(?string $telephone): static
    {
        $this->telephone = $telephone ? trim($telephone) : null;

        return $this;
    }

    public function getEmail(): ?string
    {
        return $this->email;
    }

    public function setEmail(?string $email): static
    {
        $this->email = $email ? mb_strtolower(trim($email)) : null;

        return $this;
    }

    public function __toString(): string
    {
        return (string) $this->nom;
    }
}
