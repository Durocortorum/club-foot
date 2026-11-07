<?php

namespace App\Entity;

use App\Repository\DemandeAccesMedicalRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Demande d'accès aux données médicales (Infirmerie ou Visite médicale), faite par un membre
 * de l'encadrement et traitée par un médecin du club ou l'administrateur.
 */
#[ORM\Entity(repositoryClass: DemandeAccesMedicalRepository::class)]
#[ORM\Index(columns: ['decision'])]
class DemandeAccesMedical implements AppartientAuClub
{
    use ClubTrait;

    public const ACCORDEE = 'accordee';
    public const REFUSEE = 'refusee';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Utilisateur $demandeur = null;

    /** Section médicale demandée : « infirmerie » ou « visite_medicale » (voir Utilisateur::SECTIONS_MEDICALES). */
    #[ORM\Column(length: 20)]
    private ?string $section = null;

    /** 1 : voir ; 2 : modifier. */
    #[ORM\Column(type: 'smallint')]
    private int $niveau = 1;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $motif = null;

    #[ORM\Column]
    private ?\DateTimeImmutable $demandeeLe = null;

    /** null tant que la demande attend ; sinon ACCORDEE ou REFUSEE. */
    #[ORM\Column(length: 10, nullable: true)]
    private ?string $decision = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $traiteeLe = null;

    /** Qui a traité la demande (nom affiché, conservé même si son compte est supprimé). */
    #[ORM\Column(length: 180, nullable: true)]
    private ?string $traiteePar = null;

    public function __construct(Utilisateur $demandeur, string $section, int $niveau, ?string $motif)
    {
        $this->demandeur = $demandeur;
        $this->section = $section;
        $this->niveau = $niveau;
        $this->motif = $motif ?: null;
        $this->demandeeLe = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getDemandeur(): ?Utilisateur
    {
        return $this->demandeur;
    }

    public function getSection(): ?string
    {
        return $this->section;
    }

    public function getSectionLibelle(): string
    {
        return Utilisateur::SECTIONS[$this->section]['libelle'];
    }

    public function getNiveau(): int
    {
        return $this->niveau;
    }

    public function getNiveauLibelle(): string
    {
        return 2 === $this->niveau ? 'modifier' : 'voir';
    }

    public function getMotif(): ?string
    {
        return $this->motif;
    }

    public function getDemandeeLe(): ?\DateTimeImmutable
    {
        return $this->demandeeLe;
    }

    public function getDecision(): ?string
    {
        return $this->decision;
    }

    public function isEnAttente(): bool
    {
        return null === $this->decision;
    }

    public function traiter(string $decision, string $par): static
    {
        $this->decision = $decision;
        $this->traiteeLe = new \DateTimeImmutable();
        $this->traiteePar = $par;

        return $this;
    }

    public function getTraiteeLe(): ?\DateTimeImmutable
    {
        return $this->traiteeLe;
    }

    public function getTraiteePar(): ?string
    {
        return $this->traiteePar;
    }
}
