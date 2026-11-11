<?php

namespace App\Entity;

use App\Repository\TarifCotisationRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Montant de la cotisation d'une catégorie pour une saison : c'est ce que doit chaque joueur
 * de la catégorie, sauf montant particulier (réduction, fratrie…) noté sur sa cotisation.
 */
#[ORM\Entity(repositoryClass: TarifCotisationRepository::class)]
#[ORM\UniqueConstraint(name: 'tarif_categorie_saison', columns: ['categorie_id', 'saison'])]
class TarifCotisation implements AppartientAuClub
{
    use ClubTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Categorie $categorie;

    #[ORM\Column(length: 9)]
    private string $saison;

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 2)]
    #[Assert\PositiveOrZero]
    private string $montant = '0';

    public function __construct(Categorie $categorie, string $saison)
    {
        $this->categorie = $categorie;
        $this->saison = $saison;
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

    public function getMontant(): float
    {
        return (float) $this->montant;
    }

    public function setMontant(float $montant): static
    {
        $this->montant = number_format($montant, 2, '.', '');

        return $this;
    }
}
