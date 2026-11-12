<?php

namespace App\Entity;

use App\Repository\SanctionRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Incident de discipline et sanctions associées (table Access « Tbl Discipline »).
 */
#[ORM\Entity(repositoryClass: SanctionRepository::class)]
class Sanction implements AppartientAuClub
{
    use ClubTrait;

    public const LIEUX = ['Scolaire', 'Club', 'Internat', 'Match', 'Entraînement'];
    public const GRAVITES = [1 => 'Faible', 2 => 'Moyenne', 3 => 'Grave'];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    #[Assert\NotNull]
    private ?Joueur $joueur = null;

    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    #[Assert\NotNull]
    private ?\DateTimeImmutable $date = null;

    #[ORM\Column(length: 255)]
    #[Assert\NotBlank]
    private ?string $motif = null;

    #[ORM\Column(length: 30, nullable: true)]
    private ?string $lieu = null;

    #[ORM\Column(length: 120, nullable: true)]
    private ?string $sanctionSportive = null;

    #[ORM\Column(length: 120, nullable: true)]
    private ?string $sanctionScolaire = null;

    #[ORM\Column(type: Types::SMALLINT, nullable: true)]
    #[Assert\Range(min: 1, max: 3)]
    private ?int $gravite = null;

    /** Amende infligée au joueur, en euros. */
    #[ORM\Column(type: Types::DECIMAL, precision: 8, scale: 2, nullable: true)]
    private ?string $amende = null;

    /** Date à laquelle l'amende a été payée ; vide tant qu'elle ne l'est pas. */
    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $amendePayeeLe = null;

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

    public function getMotif(): ?string
    {
        return $this->motif;
    }

    public function setMotif(?string $motif): static
    {
        $this->motif = $motif;

        return $this;
    }

    public function getLieu(): ?string
    {
        return $this->lieu;
    }

    public function setLieu(?string $lieu): static
    {
        $this->lieu = $lieu;

        return $this;
    }

    public function getSanctionSportive(): ?string
    {
        return $this->sanctionSportive;
    }

    public function setSanctionSportive(?string $sanctionSportive): static
    {
        $this->sanctionSportive = $sanctionSportive;

        return $this;
    }

    public function getSanctionScolaire(): ?string
    {
        return $this->sanctionScolaire;
    }

    public function setSanctionScolaire(?string $sanctionScolaire): static
    {
        $this->sanctionScolaire = $sanctionScolaire;

        return $this;
    }

    public function getGravite(): ?int
    {
        return $this->gravite;
    }

    public function setGravite(?int $gravite): static
    {
        $this->gravite = $gravite;

        return $this;
    }

    public function getGraviteLibelle(): ?string
    {
        return self::GRAVITES[$this->gravite] ?? null;
    }

    public function getAmende(): ?float
    {
        return null === $this->amende ? null : (float) $this->amende;
    }

    public function setAmende(?float $amende): static
    {
        $this->amende = null === $amende || $amende <= 0 ? null : number_format($amende, 2, '.', '');
        if (null === $this->amende) {
            $this->amendePayeeLe = null;
        }

        return $this;
    }

    public function getAmendePayeeLe(): ?\DateTimeImmutable
    {
        return $this->amendePayeeLe;
    }

    public function setAmendePayeeLe(?\DateTimeImmutable $date): static
    {
        $this->amendePayeeLe = null === $this->amende ? null : $date;

        return $this;
    }

    /** Une amende est infligée et pas encore payée. */
    public function isAmendeAPayer(): bool
    {
        return null !== $this->amende && null === $this->amendePayeeLe;
    }
}
