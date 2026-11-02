<?php

namespace App\Entity;

use App\Repository\BulletinScolaireRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Bulletin de notes d'un joueur. Dans Access, les bulletins étaient des liens
 * vers des PDF du serveur du club (« Tbl Scolaire », champs Images 1 à 3) :
 * le chemin d'origine est conservé tant que le PDF n'a pas été joint.
 */
#[ORM\Entity(repositoryClass: BulletinScolaireRepository::class)]
class BulletinScolaire implements AppartientAuClub
{
    use ClubTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Joueur $joueur = null;

    #[ORM\Column(length: 9, nullable: true)]
    #[Assert\Regex('#^\d{4}/\d{4}$#', message: 'Saison au format 2024/2025.')]
    private ?string $saison = null;

    #[ORM\Column(length: 120)]
    #[Assert\NotBlank]
    private ?string $libelle = null;

    /** Nom du PDF dans var/documents, null tant qu'il n'a pas été joint. */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $fichier = null;

    /** Emplacement du PDF sur l'ancien serveur, repris d'Access. */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $origine = null;

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

    public function getSaison(): ?string
    {
        return $this->saison;
    }

    public function setSaison(?string $saison): static
    {
        $this->saison = $saison;

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

    public function getFichier(): ?string
    {
        return $this->fichier;
    }

    public function setFichier(?string $fichier): static
    {
        $this->fichier = $fichier;

        return $this;
    }

    public function getOrigine(): ?string
    {
        return $this->origine;
    }

    public function setOrigine(?string $origine): static
    {
        $this->origine = $origine;

        return $this;
    }
}
