<?php

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Champs communs aux fiches éducateur et dirigeant (identiques dans Access).
 */
#[ORM\MappedSuperclass]
abstract class Intervenant implements AppartientAuClub
{
    use ClubTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    protected ?int $id = null;

    #[ORM\Column(length: 50)]
    #[Assert\NotBlank]
    protected ?string $nom = null;

    #[ORM\Column(length: 50)]
    #[Assert\NotBlank]
    protected ?string $prenom = null;

    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    protected ?\DateTimeImmutable $dateNaissance = null;

    #[ORM\Column(length: 120, nullable: true)]
    protected ?string $adresse = null;

    #[ORM\Column(length: 10, nullable: true)]
    protected ?string $codePostal = null;

    #[ORM\Column(length: 100, nullable: true)]
    protected ?string $ville = null;

    #[ORM\Column(length: 20, nullable: true)]
    protected ?string $telFixe = null;

    #[ORM\Column(length: 20, nullable: true)]
    protected ?string $telPortable1 = null;

    #[ORM\Column(length: 20, nullable: true)]
    protected ?string $telPortable2 = null;

    #[ORM\Column(length: 20, nullable: true)]
    protected ?string $telProfessionnel = null;

    #[ORM\Column(length: 180, nullable: true)]
    #[Assert\Email]
    protected ?string $email = null;

    #[ORM\Column(length: 20, nullable: true)]
    protected ?string $licence = null;

    /** Équipe ou fonction principale (champ « Equipe » dans Access). */
    #[ORM\Column(length: 255, nullable: true)]
    protected ?string $fonction = null;

    #[ORM\Column(length: 255, nullable: true)]
    protected ?string $fonction2 = null;

    #[ORM\Column(length: 255, nullable: true)]
    protected ?string $diplome = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    protected ?string $memo = null;

    #[ORM\Column(length: 255, nullable: true)]
    protected ?string $photo = null;

    #[ORM\Column]
    protected bool $ancien = false;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getNom(): ?string
    {
        return $this->nom;
    }

    /** Nom de famille, toujours en majuscules. */
    public function setNom(string $nom): static
    {
        $this->nom = mb_strtoupper(trim($nom));

        return $this;
    }

    public function getPrenom(): ?string
    {
        return $this->prenom;
    }

    public function setPrenom(string $prenom): static
    {
        $this->prenom = Prenom::formater($prenom);

        return $this;
    }

    public function getDateNaissance(): ?\DateTimeImmutable
    {
        return $this->dateNaissance;
    }

    public function setDateNaissance(?\DateTimeImmutable $dateNaissance): static
    {
        $this->dateNaissance = $dateNaissance;

        return $this;
    }

    public function getAdresse(): ?string
    {
        return $this->adresse;
    }

    public function setAdresse(?string $adresse): static
    {
        $this->adresse = $adresse;

        return $this;
    }

    public function getCodePostal(): ?string
    {
        return $this->codePostal;
    }

    public function setCodePostal(?string $codePostal): static
    {
        $this->codePostal = $codePostal;

        return $this;
    }

    public function getVille(): ?string
    {
        return $this->ville;
    }

    public function setVille(?string $ville): static
    {
        $this->ville = $ville;

        return $this;
    }

    public function getTelFixe(): ?string
    {
        return $this->telFixe;
    }

    public function setTelFixe(?string $telFixe): static
    {
        $this->telFixe = $telFixe;

        return $this;
    }

    public function getTelPortable1(): ?string
    {
        return $this->telPortable1;
    }

    public function setTelPortable1(?string $telPortable1): static
    {
        $this->telPortable1 = $telPortable1;

        return $this;
    }

    public function getTelPortable2(): ?string
    {
        return $this->telPortable2;
    }

    public function setTelPortable2(?string $telPortable2): static
    {
        $this->telPortable2 = $telPortable2;

        return $this;
    }

    public function getTelProfessionnel(): ?string
    {
        return $this->telProfessionnel;
    }

    public function setTelProfessionnel(?string $telProfessionnel): static
    {
        $this->telProfessionnel = $telProfessionnel;

        return $this;
    }

    public function getEmail(): ?string
    {
        return $this->email;
    }

    public function setEmail(?string $email): static
    {
        $this->email = $email;

        return $this;
    }

    public function getLicence(): ?string
    {
        return $this->licence;
    }

    public function setLicence(?string $licence): static
    {
        $this->licence = $licence;

        return $this;
    }

    public function getFonction(): ?string
    {
        return $this->fonction;
    }

    public function setFonction(?string $fonction): static
    {
        $this->fonction = $fonction;

        return $this;
    }

    public function getFonction2(): ?string
    {
        return $this->fonction2;
    }

    public function setFonction2(?string $fonction2): static
    {
        $this->fonction2 = $fonction2;

        return $this;
    }

    public function getDiplome(): ?string
    {
        return $this->diplome;
    }

    public function setDiplome(?string $diplome): static
    {
        $this->diplome = $diplome;

        return $this;
    }

    public function getMemo(): ?string
    {
        return $this->memo;
    }

    public function setMemo(?string $memo): static
    {
        $this->memo = $memo;

        return $this;
    }

    public function getPhoto(): ?string
    {
        return $this->photo;
    }

    public function setPhoto(?string $photo): static
    {
        $this->photo = $photo;

        return $this;
    }

    public function isAncien(): bool
    {
        return $this->ancien;
    }

    public function setAncien(bool $ancien): static
    {
        $this->ancien = $ancien;

        return $this;
    }

    public function getNomComplet(): string
    {
        return trim($this->prenom . ' ' . $this->nom);
    }

    public function __toString(): string
    {
        return $this->getNomComplet();
    }
}
