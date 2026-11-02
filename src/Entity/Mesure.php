<?php

namespace App\Entity;

use App\Repository\MesureRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Relevé de la visite médicale : poids et taille (table Access « Tbl VisiteMedicale »).
 */
#[ORM\Entity(repositoryClass: MesureRepository::class)]
class Mesure implements AppartientAuClub
{
    use ClubTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Joueur $joueur = null;

    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    #[Assert\NotNull]
    private ?\DateTimeImmutable $date = null;

    /** En kilogrammes. */
    #[ORM\Column(nullable: true)]
    #[Assert\Range(min: 10, max: 150)]
    private ?float $poids = null;

    /** En mètres. */
    #[ORM\Column(nullable: true)]
    #[Assert\Range(min: 0.8, max: 2.3)]
    private ?float $taille = null;

    /** En mètres. */
    #[ORM\Column(nullable: true)]
    #[Assert\Range(min: 0.4, max: 1.3)]
    private ?float $tailleAssis = null;

    #[ORM\Column(nullable: true)]
    #[Assert\Range(min: 20, max: 52)]
    private ?float $pointure = null;

    public function getId(): ?int
    {
        return $this->id;
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

    public function getDate(): ?\DateTimeImmutable
    {
        return $this->date;
    }

    public function setDate(?\DateTimeImmutable $date): static
    {
        $this->date = $date;

        return $this;
    }

    public function getPoids(): ?float
    {
        return $this->poids;
    }

    public function setPoids(?float $poids): static
    {
        $this->poids = $poids;

        return $this;
    }

    public function getTaille(): ?float
    {
        return $this->taille;
    }

    public function setTaille(?float $taille): static
    {
        $this->taille = $taille;

        return $this;
    }

    public function getTailleAssis(): ?float
    {
        return $this->tailleAssis;
    }

    public function setTailleAssis(?float $tailleAssis): static
    {
        $this->tailleAssis = $tailleAssis;

        return $this;
    }

    public function getPointure(): ?float
    {
        return $this->pointure;
    }

    public function setPointure(?float $pointure): static
    {
        $this->pointure = $pointure;

        return $this;
    }

    /** Indice de masse corporelle. */
    public function getImc(): ?float
    {
        return $this->poids && $this->taille ? round($this->poids / ($this->taille ** 2), 1) : null;
    }
}
