<?php

namespace App\Entity;

use App\Repository\ConvoqueRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Joueur convoqué à un match, avec la réponse de sa famille (ou saisie par l'éducateur).
 */
#[ORM\Entity(repositoryClass: ConvoqueRepository::class)]
#[ORM\UniqueConstraint(columns: ['convocation_id', 'joueur_id'])]
class Convoque implements AppartientAuClub
{
    use ClubTrait;

    public const PRESENT = 'present';
    public const ABSENT = 'absent';
    /** Motifs d'absence proposés (le texte reste libre). */
    public const MOTIFS = ['Blessé', 'Malade', 'Indisponible (famille)', 'Scolaire', 'Transport impossible'];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'convoques')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Convocation $convocation = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Joueur $joueur = null;

    /** « present », « absent », ou null tant que personne n'a répondu. */
    #[ORM\Column(length: 10, nullable: true)]
    private ?string $reponse = null;

    #[ORM\Column(length: 120, nullable: true)]
    private ?string $motif = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $reponduLe = null;

    /** Qui a répondu : la famille (son adresse e-mail) ou l'éducateur (son nom). */
    #[ORM\Column(length: 180, nullable: true)]
    private ?string $reponduPar = null;

    public function __construct(Convocation $convocation, Joueur $joueur)
    {
        $this->convocation = $convocation;
        $this->joueur = $joueur;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getConvocation(): ?Convocation
    {
        return $this->convocation;
    }

    public function getJoueur(): ?Joueur
    {
        return $this->joueur;
    }

    public function getReponse(): ?string
    {
        return $this->reponse;
    }

    public function getMotif(): ?string
    {
        return $this->motif;
    }

    public function getReponduLe(): ?\DateTimeImmutable
    {
        return $this->reponduLe;
    }

    public function getReponduPar(): ?string
    {
        return $this->reponduPar;
    }

    /** Enregistre une réponse ; null efface la réponse (retour à « sans réponse »). */
    public function repondre(?string $reponse, ?string $motif, string $par): static
    {
        if (!\in_array($reponse, [self::PRESENT, self::ABSENT, null], true)) {
            throw new \InvalidArgumentException('Réponse inconnue : ' . $reponse);
        }
        $this->reponse = $reponse;
        $this->motif = self::ABSENT === $reponse ? (mb_substr(trim((string) $motif), 0, 120) ?: null) : null;
        $this->reponduLe = null === $reponse ? null : new \DateTimeImmutable();
        $this->reponduPar = null === $reponse ? null : mb_substr($par, 0, 180);

        return $this;
    }
}
