<?php

namespace App\Entity;

use App\Repository\CommandeBoutiqueRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Commande d'une famille à la boutique du club : à régler (en ligne ou au club), payée, prête à retirer, remise
 * (ou annulée, le stock est alors remis). Les lignes gardent le nom et le prix de l'article au moment de la commande.
 */
#[ORM\Entity(repositoryClass: CommandeBoutiqueRepository::class)]
class CommandeBoutique implements AppartientAuClub
{
    use ClubTrait;

    public const A_REGLER = 'a_regler';
    public const PAYEE = 'payee';
    public const PRETE = 'prete';
    public const REMISE = 'remise';
    public const ANNULEE = 'annulee';
    public const STATUTS = [
        self::A_REGLER => 'À régler',
        self::PAYEE => 'Payée, à préparer',
        self::PRETE => 'Prête à retirer',
        self::REMISE => 'Remise',
        self::ANNULEE => 'Annulée',
    ];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?Utilisateur $compte = null;

    /** Nom de la famille, gardé si le compte est supprimé. */
    #[ORM\Column(length: 160)]
    private string $client = '';

    /** Joueur pour qui est la commande (pour la reconnaître au retrait). */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?Joueur $joueur = null;

    #[ORM\Column(length: 15)]
    private string $statut = self::A_REGLER;

    /** Total, en centimes. */
    #[ORM\Column]
    private int $total = 0;

    /** Mode de règlement : « En ligne (HelloAsso) », ou celui noté par le club (espèces, chèque…). */
    #[ORM\Column(length: 40, nullable: true)]
    private ?string $modeReglement = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $note = null;

    /** @var Collection<int, LigneCommande> */
    #[ORM\OneToMany(targetEntity: LigneCommande::class, mappedBy: 'commande', cascade: ['persist'], orphanRemoval: true)]
    private Collection $lignes;

    #[ORM\Column]
    private \DateTimeImmutable $creeLe;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $payeeLe = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $remiseLe = null;

    /** $compte null : commande saisie par le club pour une famille sans compte (le club vient alors du joueur). */
    public function __construct(?Utilisateur $compte, string $client, ?Joueur $joueur)
    {
        $this->compte = $compte;
        if ($club = $compte?->getClub() ?? $joueur?->getClub()) {
            $this->setClub($club);
        }
        $this->client = mb_substr($client, 0, 160);
        $this->joueur = $joueur;
        $this->lignes = new ArrayCollection();
        $this->creeLe = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getNumero(): string
    {
        return \sprintf('%05d', (int) $this->id);
    }

    public function getCompte(): ?Utilisateur
    {
        return $this->compte;
    }

    public function getClient(): string
    {
        return $this->client;
    }

    public function getJoueur(): ?Joueur
    {
        return $this->joueur;
    }

    public function getStatut(): string
    {
        return $this->statut;
    }

    public function getStatutLibelle(): string
    {
        return self::STATUTS[$this->statut];
    }

    public function isARegler(): bool
    {
        return self::A_REGLER === $this->statut;
    }

    public function isAnnulee(): bool
    {
        return self::ANNULEE === $this->statut;
    }

    public function isPayee(): bool
    {
        return \in_array($this->statut, [self::PAYEE, self::PRETE, self::REMISE], true);
    }

    public function getTotal(): int
    {
        return $this->total;
    }

    public function getTotalEuros(): float
    {
        return $this->total / 100;
    }

    public function getModeReglement(): ?string
    {
        return $this->modeReglement;
    }

    public function getNote(): ?string
    {
        return $this->note;
    }

    public function setNote(?string $note): static
    {
        $this->note = null === $note ? null : (mb_substr(trim($note), 0, 255) ?: null);

        return $this;
    }

    /** @return Collection<int, LigneCommande> */
    public function getLignes(): Collection
    {
        return $this->lignes;
    }

    public function ajouterLigne(ArticleBoutique $article, string $taille, int $quantite): static
    {
        $ligne = new LigneCommande($this, $article, $taille, $quantite);
        $this->lignes->add($ligne);
        $this->total += $ligne->getMontant();

        return $this;
    }

    public function getNombreArticles(): int
    {
        return array_sum($this->lignes->map(fn (LigneCommande $l) => $l->getQuantite())->toArray());
    }

    public function marquerPayee(string $mode): static
    {
        if ($this->isARegler()) {
            $this->statut = self::PAYEE;
            $this->modeReglement = mb_substr($mode, 0, 40);
            $this->payeeLe = new \DateTimeImmutable();
        }

        return $this;
    }

    public function marquerPrete(): static
    {
        if (self::PAYEE === $this->statut) {
            $this->statut = self::PRETE;
        }

        return $this;
    }

    public function marquerRemise(): static
    {
        if (\in_array($this->statut, [self::PAYEE, self::PRETE], true)) {
            $this->statut = self::REMISE;
            $this->remiseLe = new \DateTimeImmutable();
        }

        return $this;
    }

    /** Annule une commande pas encore remise ; renvoie vrai si elle l'a été (le stock est à remettre). */
    public function annuler(): bool
    {
        if (\in_array($this->statut, [self::REMISE, self::ANNULEE], true)) {
            return false;
        }
        $this->statut = self::ANNULEE;

        return true;
    }

    public function getCreeLe(): \DateTimeImmutable
    {
        return $this->creeLe;
    }

    public function getPayeeLe(): ?\DateTimeImmutable
    {
        return $this->payeeLe;
    }

    public function getRemiseLe(): ?\DateTimeImmutable
    {
        return $this->remiseLe;
    }
}
