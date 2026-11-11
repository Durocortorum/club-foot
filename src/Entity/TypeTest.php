<?php

namespace App\Entity;

use App\Repository\TypeTestRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Test du catalogue du club : tests connus du football (VAMEVAL, sprints, Illinois, jonglerie…, voir
 * CatalogueTests) ou créés par le club. Famille, unité, sens du progrès, protocole, matériel, âges conseillés.
 */
#[ORM\Entity(repositoryClass: TypeTestRepository::class)]
class TypeTest implements AppartientAuClub
{
    use ClubTrait;

    public const FAMILLES = ['Endurance', 'Vitesse', 'Agilité', 'Puissance', 'Technique', 'Souplesse et gainage'];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** Identifiant d'un test du catalogue (« vameval », « sprint20 »…) ; null pour un test créé par le club. */
    #[ORM\Column(length: 30, nullable: true)]
    private ?string $code = null;

    #[ORM\Column(length: 80)]
    #[Assert\NotBlank(message: 'Indiquez le nom du test.')]
    private ?string $nom = null;

    #[ORM\Column(length: 30)]
    #[Assert\Choice(choices: self::FAMILLES)]
    private string $famille = 'Vitesse';

    /** Unité du résultat : s, km/h, m, cm, nb, points… */
    #[ORM\Column(length: 15)]
    #[Assert\NotBlank(message: 'Indiquez l\'unité.')]
    private ?string $unite = null;

    /** Un résultat plus bas est meilleur (un temps). */
    #[ORM\Column]
    private bool $plusBasMieux = false;

    /** Ce qu'on mesure, en une phrase. */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $objectif = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $protocole = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $materiel = null;

    /** Âges conseillés, en années (U9 = 9) ; null : pas de limite. */
    #[ORM\Column(type: Types::SMALLINT, nullable: true)]
    private ?int $ageMin = null;

    #[ORM\Column(type: Types::SMALLINT, nullable: true)]
    private ?int $ageMax = null;

    #[ORM\Column(type: Types::SMALLINT)]
    private int $ordre = 0;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCode(): ?string
    {
        return $this->code;
    }

    public function setCode(?string $code): static
    {
        $this->code = $code;

        return $this;
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

    public function getFamille(): string
    {
        return $this->famille;
    }

    public function setFamille(string $famille): static
    {
        $this->famille = $famille;

        return $this;
    }

    public function getUnite(): ?string
    {
        return $this->unite;
    }

    public function setUnite(?string $unite): static
    {
        $this->unite = $unite ? trim($unite) : null;

        return $this;
    }

    public function isPlusBasMieux(): bool
    {
        return $this->plusBasMieux;
    }

    public function setPlusBasMieux(bool $plusBasMieux): static
    {
        $this->plusBasMieux = $plusBasMieux;

        return $this;
    }

    public function getObjectif(): ?string
    {
        return $this->objectif;
    }

    public function setObjectif(?string $objectif): static
    {
        $this->objectif = $objectif ?: null;

        return $this;
    }

    public function getProtocole(): ?string
    {
        return $this->protocole;
    }

    public function setProtocole(?string $protocole): static
    {
        $this->protocole = $protocole ?: null;

        return $this;
    }

    public function getMateriel(): ?string
    {
        return $this->materiel;
    }

    public function setMateriel(?string $materiel): static
    {
        $this->materiel = $materiel ?: null;

        return $this;
    }

    public function getAgeMin(): ?int
    {
        return $this->ageMin;
    }

    public function setAgeMin(?int $ageMin): static
    {
        $this->ageMin = $ageMin;

        return $this;
    }

    public function getAgeMax(): ?int
    {
        return $this->ageMax;
    }

    public function setAgeMax(?int $ageMax): static
    {
        $this->ageMax = $ageMax;

        return $this;
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

    /** « Dès U9 », « U9 à U13 », « Tous âges ». */
    public function getAges(): string
    {
        return match (true) {
            null === $this->ageMin && null === $this->ageMax => 'Tous âges',
            null === $this->ageMax => 'Dès U' . $this->ageMin,
            null === $this->ageMin => 'Jusqu\'aux U' . $this->ageMax,
            default => 'U' . $this->ageMin . ' à U' . $this->ageMax,
        };
    }

    /** Conseillé pour cet âge (U-nombre de la catégorie ; null : seniors, 20). */
    public function convientA(?int $age): bool
    {
        $age ??= 20;

        return (null === $this->ageMin || $age >= $this->ageMin) && (null === $this->ageMax || $age <= $this->ageMax);
    }

    public function __toString(): string
    {
        return (string) $this->nom;
    }
}
