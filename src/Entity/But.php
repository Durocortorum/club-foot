<?php

namespace App\Entity;

use App\Repository\ButRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * But marqué ou encaissé (tables Access « Tbl Buteurs2 » et « Tbl Buteurs Adverses2 »).
 * Un but adverse n'a ni buteur ni passeur.
 */
#[ORM\Entity(repositoryClass: ButRepository::class)]
class But implements AppartientAuClub
{
    use ClubTrait;

    public const GESTES = ['Pied droit', 'Pied gauche', 'Tête', 'Poitrine', 'Talonnade'];
    public const CONDITIONS = [
        'Tir dans la surface', 'Tir hors surface', 'Tir croisé', 'Reprise de volée', 'Centre venant de la droite',
        'Centre venant de la gauche', 'Action collective', 'Action individuelle', 'Pénalty', 'Corner droit',
        'Corner gauche', 'CF direct', 'CF indirect', 'CSC',
    ];
    /** Phase de jeu : attaque placée, attaque rapide, coup de pied arrêté. */
    public const PHASES = ['AP' => 'Attaque placée', 'AR' => 'Attaque rapide', 'CPA' => 'Coup de pied arrêté'];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'buts')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Rencontre $rencontre = null;

    #[ORM\Column]
    private bool $adverse = false;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?Joueur $buteur = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?Joueur $passeur = null;

    #[ORM\Column(nullable: true)]
    #[Assert\Range(min: 0, max: 130)]
    private ?int $minute = null;

    #[ORM\Column(length: 30, nullable: true)]
    private ?string $geste = null;

    #[ORM\Column(length: 60, nullable: true)]
    private ?string $conditions = null;

    #[ORM\Column(length: 5, nullable: true)]
    private ?string $phase = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getRencontre(): ?Rencontre
    {
        return $this->rencontre;
    }

    public function setRencontre(?Rencontre $rencontre): static
    {
        $this->rencontre = $rencontre;

        return $this;
    }

    public function isAdverse(): bool
    {
        return $this->adverse;
    }

    public function setAdverse(bool $adverse): static
    {
        $this->adverse = $adverse;

        return $this;
    }

    public function getButeur(): ?Joueur
    {
        return $this->buteur;
    }

    public function setButeur(?Joueur $buteur): static
    {
        $this->buteur = $buteur;

        return $this;
    }

    public function getPasseur(): ?Joueur
    {
        return $this->passeur;
    }

    public function setPasseur(?Joueur $passeur): static
    {
        $this->passeur = $passeur;

        return $this;
    }

    public function getMinute(): ?int
    {
        return $this->minute;
    }

    public function setMinute(?int $minute): static
    {
        $this->minute = $minute;

        return $this;
    }

    public function getGeste(): ?string
    {
        return $this->geste;
    }

    public function setGeste(?string $geste): static
    {
        $this->geste = $geste;

        return $this;
    }

    public function getConditions(): ?string
    {
        return $this->conditions;
    }

    public function setConditions(?string $conditions): static
    {
        $this->conditions = $conditions;

        return $this;
    }

    public function getPhase(): ?string
    {
        return $this->phase;
    }

    public function setPhase(?string $phase): static
    {
        $this->phase = $phase;

        return $this;
    }

    public function getPhaseLibelle(): ?string
    {
        return self::PHASES[$this->phase] ?? $this->phase;
    }
}
