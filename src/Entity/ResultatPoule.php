<?php

namespace App\Entity;

use App\Repository\ResultatPouleRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Score d'un match de la poule entre deux autres équipes (les matchs du club sont ses rencontres),
 * saisi pour recalculer le classement sans attendre la mise à jour de la FFF.
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

    #[ORM\Column(type: Types::SMALLINT)]
    #[Assert\Range(min: 0, max: 99)]
    private int $butsDomicile;

    #[ORM\Column(type: Types::SMALLINT)]
    #[Assert\Range(min: 0, max: 99)]
    private int $butsExterieur;

    public function __construct(Categorie $categorie, string $saison, \DateTimeImmutable $date, string $domicile, string $exterieur, int $butsDomicile, int $butsExterieur)
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

    public function getButsDomicile(): int
    {
        return $this->butsDomicile;
    }

    public function getButsExterieur(): int
    {
        return $this->butsExterieur;
    }
}
