<?php

namespace App\Entity;

use App\Repository\CategorieRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;

/**
 * Catégorie d'âge ou équipe (U9, U15, Seniors...) d'un club, rattachée à un de ses secteurs.
 */
#[ORM\Entity(repositoryClass: CategorieRepository::class)]
#[ORM\UniqueConstraint(columns: ['club_id', 'nom'])]
#[UniqueEntity(['club', 'nom'], message: 'Cette catégorie existe déjà.')]
class Categorie implements AppartientAuClub
{
    use ClubTrait;

    public const SECTEUR_ECOLE_FOOT = 'ecole_foot';
    public const SECTEUR_PREFORMATION = 'preformation';
    public const SECTEUR_FORMATION = 'formation';
    public const SECTEUR_ELITE = 'elite';
    public const SECTEUR_PRO = 'pro';

    /** Libellé et rôle d'accès de chaque secteur, dans l'ordre d'affichage. */
    public const SECTEURS = [
        self::SECTEUR_ECOLE_FOOT => ['libelle' => 'École de foot', 'role' => 'ROLE_ECOLE_FOOT'],
        self::SECTEUR_PREFORMATION => ['libelle' => 'Préformation', 'role' => 'ROLE_PREFORMATION'],
        self::SECTEUR_FORMATION => ['libelle' => 'Formation', 'role' => 'ROLE_FORMATION'],
        self::SECTEUR_ELITE => ['libelle' => 'Élite', 'role' => 'ROLE_ELITE'],
        self::SECTEUR_PRO => ['libelle' => 'Pro', 'role' => 'ROLE_PRO'],
    ];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 30)]
    private ?string $nom = null;

    #[ORM\Column(length: 20)]
    private ?string $secteur = null;

    #[ORM\Column]
    private int $ordre = 0;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getNom(): ?string
    {
        return $this->nom;
    }

    public function setNom(string $nom): static
    {
        $this->nom = $nom;

        return $this;
    }

    public function getSecteur(): ?string
    {
        return $this->secteur;
    }

    public function setSecteur(string $secteur): static
    {
        $this->secteur = $secteur;

        return $this;
    }

    public function getSecteurLibelle(): string
    {
        return self::SECTEURS[$this->secteur]['libelle'] ?? (string) $this->secteur;
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

    public function __toString(): string
    {
        return (string) $this->nom;
    }
}
