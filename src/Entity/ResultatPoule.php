<?php

namespace App\Entity;

use App\Repository\ResultatPouleRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Match de la poule entre deux autres équipes (les matchs du club sont ses rencontres) : importé du
 * calendrier de la poule (FFF) ou saisi ; son score, une fois connu, sert à recalculer le classement.
 */
#[ORM\Entity(repositoryClass: ResultatPouleRepository::class)]
#[ORM\Index(columns: ['saison'])]
class ResultatPoule implements AppartientAuClub
{
    use ClubTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Categorie $categorie;

    /** Saison au format « 2026/2027 ». */
    #[ORM\Column(length: 9)]
    private string $saison;

    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    private \DateTimeImmutable $date;

    #[ORM\Column(length: 80)]
    #[Assert\NotBlank]
    private string $domicile;

    #[ORM\Column(length: 80)]
    #[Assert\NotBlank]
    private string $exterieur;

    /** Score : vide tant que le match n'est pas joué. */
    #[ORM\Column(type: Types::SMALLINT, nullable: true)]
    #[Assert\Range(min: 0, max: 99)]
    private ?int $butsDomicile;

    #[ORM\Column(type: Types::SMALLINT, nullable: true)]
    #[Assert\Range(min: 0, max: 99)]
    private ?int $butsExterieur;

    /** Numéro de la journée (calendrier FFF) ; vide si inconnu (les matchs sont alors groupés par date). */
    #[ORM\Column(type: Types::SMALLINT, nullable: true)]
    private ?int $journee = null;

    public function __construct(Categorie $categorie, string $saison, \DateTimeImmutable $date, string $domicile, string $exterieur, ?int $butsDomicile = null, ?int $butsExterieur = null)
    {
        $this->categorie = $categorie;
        $this->saison = $saison;
        $this->date = $date;
        $this->domicile = $domicile;
        $this->exterieur = $exterieur;
        $this->butsDomicile = $butsDomicile;
        $this->butsExterieur = $butsExterieur;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCategorie(): Categorie
    {
        return $this->categorie;
    }

    public function getSaison(): string
    {
        return $this->saison;
    }

    public function getDate(): \DateTimeImmutable
    {
        return $this->date;
    }

    public function getDomicile(): string
    {
        return $this->domicile;
    }

    public function getExterieur(): string
    {
        return $this->exterieur;
    }

    public function getButsDomicile(): ?int
    {
        return $this->butsDomicile;
    }

    public function getButsExterieur(): ?int
    {
        return $this->butsExterieur;
    }

    /** Score saisi (les deux ou aucun) ; un seul côté rempli est ignoré. */
    public function setScore(?int $domicile, ?int $exterieur): static
    {
        [$this->butsDomicile, $this->butsExterieur] = null === $domicile || null === $exterieur ? [null, null] : [$domicile, $exterieur];

        return $this;
    }

    public function isJoue(): bool
    {
        return null !== $this->butsDomicile && null !== $this->butsExterieur;
    }

    public function getJournee(): ?int
    {
        return $this->journee;
    }

    public function setJournee(?int $journee): static
    {
        $this->journee = $journee;

        return $this;
    }

    public function setDate(\DateTimeImmutable $date): static
    {
        $this->date = $date;

        return $this;
    }
}
