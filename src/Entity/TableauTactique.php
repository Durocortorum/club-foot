<?php

namespace App\Entity;

use App\Repository\TableauTactiqueRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Tableau tactique d'un match, pour la causerie et la préparation : terrain avec la formation, déplacements,
 * passes, zones (pressing), étapes animées, et les consignes de l'éducateur. Peut être montré aux familles
 * des joueurs convoqués (espace famille).
 */
#[ORM\Entity(repositoryClass: TableauTactiqueRepository::class)]
class TableauTactique implements AppartientAuClub
{
    use ClubTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Rencontre $rencontre;

    #[ORM\Column(length: 80)]
    #[Assert\NotBlank(message: 'Donnez un titre au tableau (ex. : Organisation, Pressing, Corners).')]
    #[Assert\Length(max: 80)]
    private string $titre = '';

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    #[Assert\Length(max: 5000)]
    private ?string $consignes = null;

    /** @var array<string, mixed> voir SchemaTerrain */
    #[ORM\Column(name: 'dessin', type: Types::JSON)]
    private array $schema = ['terrain' => 'entier', 'objets' => []];

    /** Ordre d'affichage et de présentation (le plus petit d'abord). */
    #[ORM\Column]
    private int $ordre = 0;

    /** Montré aux familles des joueurs convoqués, dans leur espace. */
    #[ORM\Column(options: ['default' => false])]
    private bool $partage = false;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $modifieLe = null;

    #[ORM\Column(length: 170, nullable: true)]
    private ?string $modifieParNom = null;

    public function __construct(Rencontre $rencontre)
    {
        $this->rencontre = $rencontre;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getRencontre(): Rencontre
    {
        return $this->rencontre;
    }

    public function getTitre(): string
    {
        return $this->titre;
    }

    public function setTitre(?string $titre): static
    {
        $this->titre = mb_substr(trim((string) $titre), 0, 80);

        return $this;
    }

    public function getConsignes(): ?string
    {
        return $this->consignes;
    }

    public function setConsignes(?string $consignes): static
    {
        $consignes = trim((string) $consignes);
        $this->consignes = '' === $consignes ? null : $consignes;

        return $this;
    }

    /** @return array<string, mixed> */
    public function getSchema(): array
    {
        return $this->schema;
    }

    /** @param array<string, mixed> $schema */
    public function setSchema(array $schema): static
    {
        $this->schema = SchemaTerrain::nettoyer($schema, 'entier');

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

    public function isPartage(): bool
    {
        return $this->partage;
    }

    public function setPartage(bool $partage): static
    {
        $this->partage = $partage;

        return $this;
    }

    public function marquerModifie(Utilisateur $par): static
    {
        $this->modifieLe = new \DateTimeImmutable();
        $this->modifieParNom = trim($par->getPrenom() . ' ' . $par->getNom());

        return $this;
    }

    public function getModifieLe(): ?\DateTimeImmutable
    {
        return $this->modifieLe;
    }

    public function getModifieParNom(): ?string
    {
        return $this->modifieParNom;
    }
}
