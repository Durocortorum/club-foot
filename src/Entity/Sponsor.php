<?php

namespace App\Entity;

use App\Repository\SponsorRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Sponsor ou partenaire du club (entreprise, particulier, collectivité), avec ses participations saison par saison.
 */
#[ORM\Entity(repositoryClass: SponsorRepository::class)]
class Sponsor implements AppartientAuClub
{
    use ClubTrait;

    public const TYPES = ['Entreprise', 'Particulier', 'Collectivité', 'Association'];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 120)]
    #[Assert\NotBlank(message: 'Champ obligatoire.')]
    #[Assert\Length(max: 120)]
    private ?string $nom = null;

    #[ORM\Column(length: 20)]
    #[Assert\Choice(choices: self::TYPES)]
    private string $type = 'Entreprise';

    /** Personne à contacter chez le sponsor. */
    #[ORM\Column(length: 120, nullable: true)]
    private ?string $contact = null;

    #[ORM\Column(length: 160, nullable: true)]
    private ?string $adresse = null;

    #[ORM\Column(length: 10, nullable: true)]
    private ?string $codePostal = null;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $ville = null;

    #[ORM\Column(length: 30, nullable: true)]
    private ?string $telephone = null;

    #[ORM\Column(length: 180, nullable: true)]
    #[Assert\Email(message: 'Adresse e-mail invalide.')]
    private ?string $email = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $siteWeb = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $memo = null;

    /** N'est plus partenaire du club : visible dans les archives. */
    #[ORM\Column]
    private bool $ancien = false;

    /** @var Collection<int, PartenariatSponsor> */
    #[ORM\OneToMany(targetEntity: PartenariatSponsor::class, mappedBy: 'sponsor', cascade: ['remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['saison' => 'DESC', 'id' => 'DESC'])]
    private Collection $partenariats;

    public function __construct()
    {
        $this->partenariats = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getNom(): ?string
    {
        return $this->nom;
    }

    /** Nom de l'entreprise ou du particulier, en majuscules comme les noms d'équipes. */
    public function setNom(?string $nom): static
    {
        $this->nom = null === $nom ? null : mb_strtoupper(trim($nom));

        return $this;
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function setType(?string $type): static
    {
        $this->type = $type ?: 'Entreprise';

        return $this;
    }

    public function getContact(): ?string
    {
        return $this->contact;
    }

    public function setContact(?string $contact): static
    {
        $this->contact = $contact;

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

    public function getTelephone(): ?string
    {
        return $this->telephone;
    }

    public function setTelephone(?string $telephone): static
    {
        $this->telephone = $telephone;

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

    public function getSiteWeb(): ?string
    {
        return $this->siteWeb;
    }

    public function setSiteWeb(?string $siteWeb): static
    {
        $this->siteWeb = $siteWeb;

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

    public function isAncien(): bool
    {
        return $this->ancien;
    }

    public function setAncien(bool $ancien): static
    {
        $this->ancien = $ancien;

        return $this;
    }

    /** @return Collection<int, PartenariatSponsor> */
    public function getPartenariats(): Collection
    {
        return $this->partenariats;
    }

    /** Montant total versé (participations en argent ou valorisées). */
    public function getTotal(?string $saison = null): float
    {
        $total = 0.0;
        foreach ($this->partenariats as $p) {
            if (null === $saison || $p->getSaison() === $saison) {
                $total += (float) $p->getMontant();
            }
        }

        return $total;
    }

    public function __toString(): string
    {
        return (string) $this->nom;
    }
}
