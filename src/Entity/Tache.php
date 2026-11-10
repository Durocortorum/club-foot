<?php

namespace App\Entity;

use App\Repository\TacheRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * Tâche confiée à un membre de l'encadrement : objet, personne, catégorie (ou tout le club), échéance.
 */
#[ORM\Entity(repositoryClass: TacheRepository::class)]
#[ORM\Index(columns: ['statut'])]
class Tache implements AppartientAuClub
{
    use ClubTrait;

    public const STATUTS = [
        'a_faire' => 'À faire',
        'en_cours' => 'En cours',
        'faite' => 'Faite',
    ];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 150)]
    #[Assert\NotBlank(message: 'Indiquez l\'objet de la tâche.')]
    private ?string $objet = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $description = null;

    /** Membre de l'encadrement chargé de la tâche… */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?Utilisateur $responsable = null;

    /** …ou joueur (sa famille est prévenue et voit la tâche dans son espace). */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'CASCADE')]
    private ?Joueur $joueur = null;

    /** Catégorie concernée ; aucune = tout le club. */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?Categorie $categorie = null;

    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $echeance = null;

    #[ORM\Column]
    private bool $urgente = false;

    #[ORM\Column(length: 20)]
    #[Assert\Choice(choices: ['a_faire', 'en_cours', 'faite'])]
    private string $statut = 'a_faire';

    /** Ce qui a été fait, remarques de la personne chargée. */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $compteRendu = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?Utilisateur $creePar = null;

    #[ORM\Column]
    private \DateTimeImmutable $creeLe;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $faiteLe = null;

    public function __construct()
    {
        $this->creeLe = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getObjet(): ?string
    {
        return $this->objet;
    }

    public function setObjet(?string $objet): static
    {
        $this->objet = null === $objet ? null : trim($objet);

        return $this;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): static
    {
        $this->description = $description;

        return $this;
    }

    public function getResponsable(): ?Utilisateur
    {
        return $this->responsable;
    }

    public function setResponsable(?Utilisateur $responsable): static
    {
        $this->responsable = $responsable;

        return $this;
    }

    public function getJoueur(): ?Joueur
    {
        return $this->joueur;
    }

    public function setJoueur(?Joueur $joueur): static
    {
        $this->joueur = $joueur;

        return $this;
    }

    /** « Paul DURAND » ou « Lucas MARTIN (joueur) ». */
    public function getChargeDe(): string
    {
        return match (true) {
            null !== $this->joueur => $this->joueur->getPrenom() . ' ' . $this->joueur->getNom() . ' (joueur)',
            null !== $this->responsable => $this->responsable->getPrenom() . ' ' . $this->responsable->getNom(),
            default => '—',
        };
    }

    #[Assert\Callback]
    public function verifierPersonne(ExecutionContextInterface $context): void
    {
        if (!$this->responsable && !$this->joueur) {
            $context->buildViolation('Choisissez une personne de l\'encadrement ou un joueur.')->atPath('responsable')->addViolation();
        } elseif ($this->responsable && $this->joueur) {
            $context->buildViolation('Choisissez soit une personne de l\'encadrement, soit un joueur, pas les deux.')->atPath('joueur')->addViolation();
        }
    }

    public function getCategorie(): ?Categorie
    {
        return $this->categorie;
    }

    public function setCategorie(?Categorie $categorie): static
    {
        $this->categorie = $categorie;

        return $this;
    }

    public function getEcheance(): ?\DateTimeImmutable
    {
        return $this->echeance;
    }

    public function setEcheance(?\DateTimeImmutable $echeance): static
    {
        $this->echeance = $echeance;

        return $this;
    }

    public function isUrgente(): bool
    {
        return $this->urgente;
    }

    public function setUrgente(bool $urgente): static
    {
        $this->urgente = $urgente;

        return $this;
    }

    public function getStatut(): string
    {
        return $this->statut;
    }

    /** Passer à « Faite » date la tâche ; la rouvrir efface cette date. */
    public function setStatut(?string $statut): static
    {
        $statut = (string) $statut;
        if ('faite' === $statut && 'faite' !== $this->statut) {
            $this->faiteLe = new \DateTimeImmutable();
        } elseif ('faite' !== $statut) {
            $this->faiteLe = null;
        }
        $this->statut = $statut;

        return $this;
    }

    public function getStatutLibelle(): string
    {
        return self::STATUTS[$this->statut] ?? $this->statut;
    }

    public function isFaite(): bool
    {
        return 'faite' === $this->statut;
    }

    /** Échéance dépassée alors que la tâche n'est pas faite. */
    public function isEnRetard(): bool
    {
        return !$this->isFaite() && $this->echeance && $this->echeance < new \DateTimeImmutable('today');
    }

    public function getCompteRendu(): ?string
    {
        return $this->compteRendu;
    }

    public function setCompteRendu(?string $compteRendu): static
    {
        $this->compteRendu = $compteRendu;

        return $this;
    }

    public function getCreePar(): ?Utilisateur
    {
        return $this->creePar;
    }

    public function setCreePar(?Utilisateur $creePar): static
    {
        $this->creePar = $creePar;

        return $this;
    }

    public function getCreeLe(): \DateTimeImmutable
    {
        return $this->creeLe;
    }

    public function getFaiteLe(): ?\DateTimeImmutable
    {
        return $this->faiteLe;
    }
}
