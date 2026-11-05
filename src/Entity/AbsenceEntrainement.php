<?php

namespace App\Entity;

use App\Repository\AbsenceEntrainementRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Absence à un entraînement, signalée par la famille (ou notée par l'éducateur).
 */
#[ORM\Entity(repositoryClass: AbsenceEntrainementRepository::class)]
#[ORM\UniqueConstraint(columns: ['entrainement_id', 'joueur_id'])]
class AbsenceEntrainement implements AppartientAuClub
{
    use ClubTrait;

    /** Motifs proposés, avec le code reporté dans la grille des présences (SemainePresence::MOTIFS). */
    public const MOTIFS = [
        'Malade' => 'Malade',
        'Blessé' => 'Blessé',
        'Scolaire' => 'Excusé',
        'Raison familiale' => 'Excusé',
        'Transport impossible' => 'Excusé',
        'Autre' => 'Excusé',
    ];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'absences')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Entrainement $entrainement = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Joueur $joueur = null;

    #[ORM\Column(length: 40)]
    private string $motif = 'Autre';

    #[ORM\Column(length: 120, nullable: true)]
    private ?string $commentaire = null;

    #[ORM\Column]
    private \DateTimeImmutable $declareeLe;

    /** La famille (son adresse e-mail) ou l'éducateur (son nom). */
    #[ORM\Column(length: 180)]
    private string $declareePar;

    public function __construct(Entrainement $entrainement, Joueur $joueur, string $motif, ?string $commentaire, string $par)
    {
        $this->entrainement = $entrainement;
        $this->joueur = $joueur;
        $this->modifier($motif, $commentaire, $par);
    }

    public function modifier(string $motif, ?string $commentaire, string $par): static
    {
        $this->motif = isset(self::MOTIFS[$motif]) ? $motif : 'Autre';
        $this->commentaire = mb_substr(trim((string) $commentaire), 0, 120) ?: null;
        $this->declareeLe = new \DateTimeImmutable();
        $this->declareePar = mb_substr($par, 0, 180);

        return $this;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getEntrainement(): ?Entrainement
    {
        return $this->entrainement;
    }

    public function getJoueur(): ?Joueur
    {
        return $this->joueur;
    }

    public function getMotif(): string
    {
        return $this->motif;
    }

    /** Code de la grille des présences : « Malade », « Blessé » ou « Excusé ». */
    public function getCodeGrille(): string
    {
        return self::MOTIFS[$this->motif] ?? 'Excusé';
    }

    public function getCommentaire(): ?string
    {
        return $this->commentaire;
    }

    public function getDeclareeLe(): \DateTimeImmutable
    {
        return $this->declareeLe;
    }

    public function getDeclareePar(): string
    {
        return $this->declareePar;
    }
}
