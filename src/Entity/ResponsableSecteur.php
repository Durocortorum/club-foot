<?php

namespace App\Entity;

use App\Repository\ResponsableSecteurRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Responsable d'un secteur (École de foot, Préformation…) : il voit tout le secteur, modifie les matchs et les
 * convocations de toutes ses équipes et choisit qui les modifie. Il peut aussi être éducateur d'une de ces équipes.
 */
#[ORM\Entity(repositoryClass: ResponsableSecteurRepository::class)]
#[ORM\UniqueConstraint(columns: ['club_id', 'secteur'])]
class ResponsableSecteur implements AppartientAuClub
{
    use ClubTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** Clé du secteur (voir Categorie::SECTEURS). */
    #[ORM\Column(length: 20)]
    private string $secteur;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Utilisateur $utilisateur;

    public function __construct(string $secteur, Utilisateur $utilisateur)
    {
        $this->secteur = $secteur;
        $this->utilisateur = $utilisateur;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getSecteur(): string
    {
        return $this->secteur;
    }

    public function getUtilisateur(): Utilisateur
    {
        return $this->utilisateur;
    }

    public function setUtilisateur(Utilisateur $utilisateur): static
    {
        $this->utilisateur = $utilisateur;

        return $this;
    }
}
