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
    /** Nombre maximal d'étapes d'animation après le départ. */
    public const MAX_ETAPES = 30;

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

    /** @var array{terrain?: string, objets?: list<array<string, mixed>>, etapes?: list<list<array{float, float}|null>>} */
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

    /** @return array{terrain?: string, objets?: list<array<string, mixed>>, etapes?: list<list<array{float, float}|null>>} */
    public function getSchema(): array
    {
        return $this->schema;
    }

    /** @param array<string, mixed> $schema */
    public function setSchema(array $schema): static
    {
        $objets = (array) ($schema['objets'] ?? []);
        $gardes = array_keys(array_filter($objets, 'is_array'));
        $this->schema = [
            'terrain' => isset(self::TERRAINS[$schema['terrain'] ?? null]) ? $schema['terrain'] : 'demi',
            'objets' => array_values(array_filter($objets, 'is_array')),
        ];
        // Animation : par étape, nouvelle place [x, y] de chaque objet (null s'il ne bouge pas), alignée sur les objets.
        $etapes = [];
        foreach (\array_slice(array_values(array_filter((array) ($schema['etapes'] ?? []), 'is_array')), 0, self::MAX_ETAPES) as $etape) {
            $etapes[] = array_map(
                fn ($i) => isset($etape[$i]) && \is_array($etape[$i]) && 2 === \count($etape[$i]) && is_numeric($etape[$i][0] ?? null) && is_numeric($etape[$i][1] ?? null)
                    ? [round((float) $etape[$i][0], 1), round((float) $etape[$i][1], 1)] : null,
                $gardes,
            );
        }
        if ($etapes) {
            $this->schema['etapes'] = $etapes;
        }

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
