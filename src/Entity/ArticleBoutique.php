<?php

namespace App\Entity;

use App\Repository\ArticleBoutiqueRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Article de la boutique du club (survêtement, maillot, chaussettes…) : prix, tailles proposées, stock éventuel
 * par taille (vide : illimité), photo. Les familles commandent depuis leur espace ; retrait au club.
 */
#[ORM\Entity(repositoryClass: ArticleBoutiqueRepository::class)]
class ArticleBoutique implements AppartientAuClub
{
    use ClubTrait;

    /** Taille unique : article sans choix de taille. */
    public const TAILLE_UNIQUE = 'Taille unique';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 120)]
    #[Assert\NotBlank(message: 'Indiquez le nom de l\'article.')]
    #[Assert\Length(max: 120)]
    private ?string $nom = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $description = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 8, scale: 2)]
    private string $prix = '0';

    /** @var list<string> tailles proposées, dans l'ordre ; vide : taille unique */
    #[ORM\Column(type: Types::JSON)]
    private array $tailles = [];

    /** @var array<string, int>|null stock restant par taille ; null : pas de suivi du stock */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $stock = null;

    #[ORM\Column(length: 60, nullable: true)]
    private ?string $photo = null;

    /** En vente (visible des familles). */
    #[ORM\Column(options: ['default' => true])]
    private bool $enVente = true;

    #[ORM\Column(options: ['default' => 0])]
    private int $ordre = 0;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getNom(): ?string
    {
        return $this->nom;
    }

    public function setNom(?string $nom): static
    {
        $this->nom = null === $nom ? null : trim($nom);

        return $this;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): static
    {
        $this->description = null === $description ? null : (trim($description) ?: null);

        return $this;
    }

    public function getPrix(): float
    {
        return (float) $this->prix;
    }

    public function setPrix(?float $prix): static
    {
        $this->prix = number_format(max(0.0, (float) $prix), 2, '.', '');

        return $this;
    }

    /** @return list<string> tailles à choisir (« Taille unique » si aucune) */
    public function getTailles(): array
    {
        return $this->tailles ?: [self::TAILLE_UNIQUE];
    }

    /** Tailles séparées par des virgules : « 6 ans, 8 ans, S, M, L ». */
    public function getTaillesTexte(): string
    {
        return implode(', ', $this->tailles);
    }

    public function setTaillesTexte(?string $texte): static
    {
        $tailles = array_values(array_unique(array_filter(array_map(fn ($t) => mb_substr(trim($t), 0, 20), explode(',', (string) $texte)))));
        $this->tailles = $tailles;
        if (null !== $this->stock) {
            // Stock des tailles retirées oublié, nouvelles tailles à zéro.
            $this->stock = array_map('intval', array_intersect_key($this->stock, array_flip($this->getTailles())) + array_fill_keys($this->getTailles(), 0));
        }

        return $this;
    }

    public function isSuiviStock(): bool
    {
        return null !== $this->stock;
    }

    /** @return array<string, int>|null */
    public function getStock(): ?array
    {
        return $this->stock;
    }

    /** @param array<string, int>|null $stock */
    public function setStock(?array $stock): static
    {
        $this->stock = null === $stock ? null : array_map(fn ($n) => max(0, (int) $n), array_intersect_key($stock, array_flip($this->getTailles())) + array_fill_keys($this->getTailles(), 0));

        return $this;
    }

    /** Quantité disponible dans cette taille ; null : illimitée. */
    public function disponible(string $taille): ?int
    {
        return null === $this->stock ? null : (int) ($this->stock[$taille] ?? 0);
    }

    public function isEpuise(): bool
    {
        return null !== $this->stock && 0 === array_sum($this->stock);
    }

    /** Retire (quantité positive) ou remet (négative) des articles en stock. */
    public function mouvementStock(string $taille, int $quantite): static
    {
        if (null !== $this->stock) {
            $this->stock[$taille] = max(0, (int) ($this->stock[$taille] ?? 0) - $quantite);
        }

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

    public function isEnVente(): bool
    {
        return $this->enVente;
    }

    public function setEnVente(bool $enVente): static
    {
        $this->enVente = $enVente;

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

    public function __toString(): string
    {
        return (string) $this->nom;
    }
}
