<?php

namespace App\Entity;

use App\Repository\CotisationRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Cotisation d'un joueur pour une saison : ses paiements et, s'il y a lieu, un montant particulier
 * (réduction, fratrie, exonération). Sans montant particulier, il doit le tarif de sa catégorie.
 * Créée au premier paiement ou à la première réduction.
 */
#[ORM\Entity(repositoryClass: CotisationRepository::class)]
#[ORM\UniqueConstraint(name: 'cotisation_joueur_saison', columns: ['joueur_id', 'saison'])]
class Cotisation implements AppartientAuClub
{
    use ClubTrait;

    /** Paiement fait par la famille depuis son espace (HelloAsso), enregistré automatiquement. */
    public const MODE_EN_LIGNE = 'En ligne (HelloAsso)';
    public const MODES = ['Espèces', 'Chèque', 'Virement', 'Carte bancaire', self::MODE_EN_LIGNE, 'Pass\'Sport', 'Coupons sport / ANCV', 'Autre'];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Joueur $joueur;

    #[ORM\Column(length: 9)]
    private string $saison;

    /** Montant particulier ; null : le tarif de la catégorie. */
    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 2, nullable: true)]
    private ?string $montant = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $remarque = null;

    /** @var Collection<int, Paiement> */
    #[ORM\OneToMany(targetEntity: Paiement::class, mappedBy: 'cotisation', cascade: ['persist'], orphanRemoval: true)]
    #[ORM\OrderBy(['date' => 'ASC', 'id' => 'ASC'])]
    private Collection $paiements;

    public function __construct(Joueur $joueur, string $saison)
    {
        $this->joueur = $joueur;
        $this->saison = $saison;
        $this->paiements = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getJoueur(): Joueur
    {
        return $this->joueur;
    }

    public function getSaison(): string
    {
        return $this->saison;
    }

    public function getMontant(): ?float
    {
        return null === $this->montant ? null : (float) $this->montant;
    }

    public function setMontant(?float $montant): static
    {
        $this->montant = null === $montant ? null : number_format($montant, 2, '.', '');

        return $this;
    }

    public function getRemarque(): ?string
    {
        return $this->remarque;
    }

    public function setRemarque(?string $remarque): static
    {
        $this->remarque = $remarque ?: null;

        return $this;
    }

    /** @return Collection<int, Paiement> */
    public function getPaiements(): Collection
    {
        return $this->paiements;
    }

    public function ajouterPaiement(Paiement $paiement): static
    {
        $this->paiements->add($paiement);

        return $this;
    }

    public function getPaye(): float
    {
        return array_sum($this->paiements->map(fn (Paiement $p) => $p->getMontant())->toArray());
    }
}
