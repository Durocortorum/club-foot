<?php

namespace App\Entity;

use App\Repository\ExerciceRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Exercice d'une séance, avec son schéma : terrain et objets placés dessus
 * (joueurs, ballons, cônes, buts, flèches, zones, textes), en mètres.
 */
#[ORM\Entity(repositoryClass: ExerciceRepository::class)]
class Exercice implements AppartientAuClub
{
    use ClubTrait;

    public const TERRAINS = ['entier' => 'Terrain entier', 'demi' => 'Demi-terrain', 'libre' => 'Zone libre'];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'exercices')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Seance $seance = null;

    #[ORM\Column]
    private int $ordre = 0;

    #[ORM\Column(length: 120)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 120)]
    private ?string $titre = null;

    /** Durée en minutes. */
    #[ORM\Column(type: Types::SMALLINT, nullable: true)]
    #[Assert\Range(min: 1, max: 300)]
    private ?int $duree = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $consignes = null;

    /** @var array{terrain?: string, objets?: list<array<string, mixed>>} */
    // « schema » est un mot réservé de MySQL.
    #[ORM\Column(name: 'dessin', type: Types::JSON)]
    private array $schema = ['terrain' => 'demi', 'objets' => []];

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getSeance(): ?Seance
    {
        return $this->seance;
    }

    public function setSeance(?Seance $seance): static
    {
        $this->seance = $seance;

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

    public function getTitre(): ?string
    {
        return $this->titre;
    }

    public function setTitre(?string $titre): static
    {
        $this->titre = $titre;

        return $this;
    }

    public function getDuree(): ?int
    {
        return $this->duree;
    }

    public function setDuree(?int $duree): static
    {
        $this->duree = $duree;

        return $this;
    }

    public function getConsignes(): ?string
    {
        return $this->consignes;
    }

    public function setConsignes(?string $consignes): static
    {
        $this->consignes = $consignes;

        return $this;
    }

    /** @return array{terrain?: string, objets?: list<array<string, mixed>>} */
    public function getSchema(): array
    {
        return $this->schema;
    }

    /** @param array<string, mixed> $schema */
    public function setSchema(array $schema): static
    {
        $this->schema = [
            'terrain' => isset(self::TERRAINS[$schema['terrain'] ?? null]) ? $schema['terrain'] : 'demi',
            'objets' => array_values(array_filter((array) ($schema['objets'] ?? []), 'is_array')),
        ];

        return $this;
    }

    /** Copie pour une autre séance (schéma compris). */
    public function copier(): self
    {
        return (new self())
            ->setTitre($this->titre)
            ->setDuree($this->duree)
            ->setConsignes($this->consignes)
            ->setSchema($this->schema);
    }
}
