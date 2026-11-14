<?php

namespace App\Entity;

use App\Repository\PaiementEnLigneRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Paiement lancé par une famille depuis son espace (HelloAsso Checkout) : ce qui est payé (cotisation d'un joueur
 * pour l'instant ; stages et boutique ensuite), montant et échéances, suivi auprès de HelloAsso.
 * Chaque échéance encaissée devient un Paiement de la cotisation (une seule fois, d'après son numéro HelloAsso).
 */
#[ORM\Entity(repositoryClass: PaiementEnLigneRepository::class)]
#[ORM\Index(name: 'paiement_en_ligne_intention', columns: ['checkout_intent_id'])]
class PaiementEnLigne implements AppartientAuClub
{
    use ClubTrait;

    public const COTISATION = 'cotisation';

    public const EN_COURS = 'en_cours';
    public const PAYE = 'paye';
    public const ABANDONNE = 'abandonne';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 20)]
    private string $objet;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Joueur $joueur;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?Cotisation $cotisation = null;

    #[ORM\Column(length: 250)]
    private string $libelle;

    /** Montant total, en centimes. */
    #[ORM\Column]
    private int $montant;

    #[ORM\Column(type: Types::SMALLINT)]
    private int $echeances = 1;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?Utilisateur $payeur = null;

    #[ORM\Column(nullable: true)]
    private ?int $checkoutIntentId = null;

    #[ORM\Column(nullable: true)]
    private ?int $commandeHelloAsso = null;

    #[ORM\Column(length: 15)]
    private string $statut = self::EN_COURS;

    /** @var list<int> numéros HelloAsso des échéances déjà enregistrées dans la cotisation */
    #[ORM\Column(type: Types::JSON)]
    private array $paiementsEnregistres = [];

    #[ORM\Column]
    private \DateTimeImmutable $creeLe;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $payeLe = null;

    public function __construct(string $objet, Joueur $joueur, string $libelle, int $montant, int $echeances, ?Utilisateur $payeur)
    {
        $this->objet = $objet;
        $this->joueur = $joueur;
        $this->setClub($joueur->getClub());
        $this->libelle = mb_substr($libelle, 0, 250);
        $this->montant = $montant;
        $this->echeances = max(1, $echeances);
        $this->payeur = $payeur;
        $this->creeLe = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getObjet(): string
    {
        return $this->objet;
    }

    public function getJoueur(): Joueur
    {
        return $this->joueur;
    }

    public function getCotisation(): ?Cotisation
    {
        return $this->cotisation;
    }

    public function setCotisation(?Cotisation $cotisation): static
    {
        $this->cotisation = $cotisation;

        return $this;
    }

    public function getLibelle(): string
    {
        return $this->libelle;
    }

    public function getMontant(): int
    {
        return $this->montant;
    }

    public function getMontantEuros(): float
    {
        return $this->montant / 100;
    }

    public function getEcheances(): int
    {
        return $this->echeances;
    }

    public function getPayeur(): ?Utilisateur
    {
        return $this->payeur;
    }

    public function getCheckoutIntentId(): ?int
    {
        return $this->checkoutIntentId;
    }

    public function setCheckoutIntentId(int $id): static
    {
        $this->checkoutIntentId = $id;

        return $this;
    }

    public function getCommandeHelloAsso(): ?int
    {
        return $this->commandeHelloAsso;
    }

    public function getStatut(): string
    {
        return $this->statut;
    }

    public function isPaye(): bool
    {
        return self::PAYE === $this->statut;
    }

    public function isEnCours(): bool
    {
        return self::EN_COURS === $this->statut;
    }

    public function marquerPaye(int $commande): static
    {
        $this->statut = self::PAYE;
        $this->commandeHelloAsso = $commande;
        $this->payeLe ??= new \DateTimeImmutable();

        return $this;
    }

    public function abandonner(): static
    {
        if (self::EN_COURS === $this->statut) {
            $this->statut = self::ABANDONNE;
        }

        return $this;
    }

    public function dejaEnregistre(int $paiementHelloAsso): bool
    {
        return \in_array($paiementHelloAsso, $this->paiementsEnregistres, true);
    }

    public function noterEnregistre(int $paiementHelloAsso): static
    {
        $this->paiementsEnregistres[] = $paiementHelloAsso;

        return $this;
    }

    public function getCreeLe(): \DateTimeImmutable
    {
        return $this->creeLe;
    }

    public function getPayeLe(): ?\DateTimeImmutable
    {
        return $this->payeLe;
    }
}
