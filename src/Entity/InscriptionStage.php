<?php

namespace App\Entity;

use App\Repository\InscriptionStageRepository;
use Doctrine\ORM\Mapping as ORM;

/** Joueur inscrit à un stage, par l'encadrement ou par sa famille ; payé ou non si le stage est payant. */
#[ORM\Entity(repositoryClass: InscriptionStageRepository::class)]
#[ORM\UniqueConstraint(columns: ['stage_id', 'joueur_id'])]
class InscriptionStage
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'inscriptions')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Stage $stage;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Joueur $joueur;

    #[ORM\Column]
    private \DateTimeImmutable $inscritLe;

    /** Inscrit par sa famille depuis l'espace famille (sinon par l'encadrement). */
    #[ORM\Column]
    private bool $parFamille;

    #[ORM\Column]
    private bool $paye = false;

    public function __construct(Stage $stage, Joueur $joueur, bool $parFamille = false)
    {
        $this->stage = $stage;
        $this->joueur = $joueur;
        $this->parFamille = $parFamille;
        $this->inscritLe = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getStage(): Stage
    {
        return $this->stage;
    }

    public function getJoueur(): Joueur
    {
        return $this->joueur;
    }

    public function getInscritLe(): \DateTimeImmutable
    {
        return $this->inscritLe;
    }

    public function isParFamille(): bool
    {
        return $this->parFamille;
    }

    public function isPaye(): bool
    {
        return $this->paye;
    }

    public function setPaye(bool $paye): static
    {
        $this->paye = $paye;

        return $this;
    }
}
