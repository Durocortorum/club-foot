<?php

namespace App\Entity;

use App\Repository\ClassementRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Classement du championnat d'une équipe pour une saison, recopié depuis la FFF ou Transfermarkt :
 * l'application ne connaît que les matchs du club, pas ceux des autres équipes de la poule.
 */
#[ORM\Entity(repositoryClass: ClassementRepository::class)]
#[ORM\UniqueConstraint(columns: ['categorie_id', 'saison'])]
class Classement implements AppartientAuClub
{
    use ClubTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Categorie $categorie = null;

    /** Saison au format « 2026/2027 ». */
    #[ORM\Column(length: 9)]
    private ?string $saison = null;

    /** @var list<array{rang: int, equipe: string, points: int, joues: int, gagnes: int, nuls: int, perdus: int, pour: int, contre: int, diff: int}> */
    #[ORM\Column(type: Types::JSON)]
    private array $lignes = [];

    #[ORM\Column]
    private ?\DateTimeImmutable $misAJourLe = null;

    public function __construct(Categorie $categorie, string $saison)
    {
        $this->categorie = $categorie;
        $this->saison = $saison;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCategorie(): ?Categorie
    {
        return $this->categorie;
    }

    public function getSaison(): ?string
    {
        return $this->saison;
    }

    /** @return list<array{rang: int, equipe: string, points: int, joues: int, gagnes: int, nuls: int, perdus: int, pour: int, contre: int, diff: int}> */
    public function getLignes(): array
    {
        return $this->lignes;
    }

    /** @param list<array<string, mixed>> $lignes */
    public function setLignes(array $lignes): static
    {
        $this->lignes = array_values($lignes);
        $this->misAJourLe = new \DateTimeImmutable();

        return $this;
    }

    public function getMisAJourLe(): ?\DateTimeImmutable
    {
        return $this->misAJourLe;
    }

    /** Ligne d'une équipe (adversaire d'un match) : même nom une fois retirés « FC », « AS », « U19 »… */
    public function ligneDe(?string $equipe): ?array
    {
        $cherche = Rencontre::coeurDuNom($equipe);
        if ('' === $cherche) {
            return null;
        }
        foreach ($this->lignes as $l) {
            $coeur = Rencontre::coeurDuNom($l['equipe']);
            if ($coeur === $cherche || str_starts_with($coeur, $cherche . ' ') || str_starts_with($cherche, $coeur . ' ')) {
                return $l;
            }
        }

        return null;
    }
}
