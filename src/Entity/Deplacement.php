<?php

namespace App\Entity;

use App\Repository\DeplacementRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Déplacement d'une équipe ou d'une personne : car de société, minibus du club, voiture personnelle, train.
 * Voiture personnelle : frais kilométriques (km × barème + péages, parking), remboursés au conducteur
 * ou abandonnés au club (don : reçu fiscal pour le bénévole).
 */
#[ORM\Entity(repositoryClass: DeplacementRepository::class)]
#[Assert\Expression('!this.isVoiture() or (this.getConducteur() and this.getKm())',message: 'Voiture personnelle : indiquez le conducteur et les kilomètres (aller et retour).')]
class Deplacement implements AppartientAuClub
{
    use ClubTrait;

    public const VOITURE = 'Voiture personnelle';
    public const MOYENS = ['Car de société', 'Minibus du club', self::VOITURE, 'Train', 'Autre'];
    /** Barème kilométrique des bénévoles (voiture), modifiable sur chaque déplacement. */
    public const BAREME_KM = 0.335;

    public const A_REMBOURSER = 'a_rembourser';
    public const REMBOURSE = 'rembourse';
    public const ABANDONNE = 'abandonne';
    public const STATUTS = [self::A_REMBOURSER => 'À rembourser', self::REMBOURSE => 'Remboursé', self::ABANDONNE => 'Don au club (abandon)'];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    #[Assert\NotNull(message: 'Indiquez la date.')]
    private ?\DateTimeImmutable $date = null;

    #[ORM\Column(length: 150)]
    #[Assert\NotBlank(message: 'Indiquez l\'objet du déplacement.')]
    private ?string $objet = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?Categorie $categorie = null;

    /** Match pour lequel on se déplace : affiché dans sa convocation et dans l'espace des familles. */
    #[ORM\ManyToOne(inversedBy: 'deplacements')]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?Rencontre $rencontre = null;

    /** Heure de départ (« 12h30 »). */
    #[ORM\Column(length: 10, nullable: true)]
    #[Assert\Length(max: 10)]
    private ?string $heureDepart = null;

    /** Lieu de départ (« parking du stade »). */
    #[ORM\Column(length: 120, nullable: true)]
    #[Assert\Length(max: 120)]
    private ?string $lieuDepart = null;

    #[ORM\Column(length: 40)]
    #[Assert\Choice(choices: self::MOYENS)]
    private string $moyen = self::VOITURE;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?Vehicule $vehicule = null;

    /** Nom du conducteur (frais kilométriques regroupés par conducteur). */
    #[ORM\Column(length: 120, nullable: true)]
    private ?string $conducteur = null;

    #[ORM\Column(type: Types::SMALLINT, nullable: true)]
    #[Assert\Range(min: 0, max: 99)]
    private ?int $passagers = null;

    /** Kilomètres aller et retour. */
    #[ORM\Column(nullable: true)]
    #[Assert\Range(min: 0, max: 20000)]
    private ?int $km = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 6, scale: 3, nullable: true)]
    #[Assert\Range(min: 0, max: 5)]
    private ?string $tauxKm = null;

    /** Péages, parking (voiture), ou prix du car, du train. */
    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 2, nullable: true)]
    #[Assert\PositiveOrZero]
    private ?string $frais = null;

    #[ORM\Column(length: 15, nullable: true)]
    private ?string $statut = null;

    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $regleLe = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $note = null;

    public function getId(): ?int
    {
        return $this->id;
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

    public function getObjet(): ?string
    {
        return $this->objet;
    }

    public function setObjet(?string $objet): static
    {
        $this->objet = $objet;

        return $this;
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

    public function getRencontre(): ?Rencontre
    {
        return $this->rencontre;
    }

    public function setRencontre(?Rencontre $rencontre): static
    {
        $this->rencontre = $rencontre;

        return $this;
    }

    public function getHeureDepart(): ?string
    {
        return $this->heureDepart;
    }

    public function setHeureDepart(?string $heureDepart): static
    {
        $this->heureDepart = null === $heureDepart ? null : (trim($heureDepart) ?: null);

        return $this;
    }

    public function getLieuDepart(): ?string
    {
        return $this->lieuDepart;
    }

    public function setLieuDepart(?string $lieuDepart): static
    {
        $this->lieuDepart = null === $lieuDepart ? null : (trim($lieuDepart) ?: null);

        return $this;
    }

    /**
     * Ce que les familles en voient : moyen, conducteur, départ (« Car de société · départ 12h30, parking du stade »).
     * Ni frais ni kilomètres.
     */
    public function getResumeFamilles(): string
    {
        $moyen = self::VOITURE === $this->moyen ? 'Voiture' : $this->moyen;
        $depart = trim(($this->heureDepart ? 'départ ' . $this->heureDepart : '') . ($this->lieuDepart ? ($this->heureDepart ? ', ' : 'départ : ') . $this->lieuDepart : ''));

        return implode(' · ', array_filter([
            $moyen . ($this->conducteur && 'Car de société' !== $this->moyen && 'Train' !== $this->moyen ? ' de ' . $this->conducteur : ''),
            $depart,
        ]));
    }

    public function getMoyen(): string
    {
        return $this->moyen;
    }

    public function setMoyen(?string $moyen): static
    {
        $this->moyen = $moyen ?? self::VOITURE;

        return $this;
    }

    public function isVoiture(): bool
    {
        return self::VOITURE === $this->moyen;
    }

    public function getVehicule(): ?Vehicule
    {
        return $this->vehicule;
    }

    public function setVehicule(?Vehicule $vehicule): static
    {
        $this->vehicule = $vehicule;

        return $this;
    }

    public function getConducteur(): ?string
    {
        return $this->conducteur;
    }

    public function setConducteur(?string $conducteur): static
    {
        $this->conducteur = $conducteur ? trim(preg_replace('/\s+/u', ' ', $conducteur) ?? $conducteur) : null;

        return $this;
    }

    public function getPassagers(): ?int
    {
        return $this->passagers;
    }

    public function setPassagers(?int $passagers): static
    {
        $this->passagers = $passagers;

        return $this;
    }

    public function getKm(): ?int
    {
        return $this->km;
    }

    public function setKm(?int $km): static
    {
        $this->km = $km;

        return $this;
    }

    public function getTauxKm(): ?float
    {
        return null === $this->tauxKm ? null : (float) $this->tauxKm;
    }

    public function setTauxKm(?float $taux): static
    {
        $this->tauxKm = null === $taux ? null : number_format($taux, 3, '.', '');

        return $this;
    }

    public function getFrais(): ?float
    {
        return null === $this->frais ? null : (float) $this->frais;
    }

    public function setFrais(?float $frais): static
    {
        $this->frais = null === $frais ? null : number_format($frais, 2, '.', '');

        return $this;
    }

    /** Voiture personnelle : km × barème + péages et parking ; sinon le prix payé. */
    public function getMontant(): float
    {
        $km = $this->isVoiture() ? ($this->km ?? 0) * ($this->getTauxKm() ?? self::BAREME_KM) : 0;

        return round($km + ($this->getFrais() ?? 0), 2);
    }

    public function getStatut(): ?string
    {
        return $this->statut;
    }

    public function setStatut(?string $statut): static
    {
        $this->statut = isset(self::STATUTS[$statut]) ? $statut : null;

        return $this;
    }

    public function getStatutLibelle(): ?string
    {
        return self::STATUTS[$this->statut] ?? null;
    }

    public function getRegleLe(): ?\DateTimeImmutable
    {
        return $this->regleLe;
    }

    public function setRegleLe(?\DateTimeImmutable $regleLe): static
    {
        $this->regleLe = $regleLe;

        return $this;
    }

    public function getNote(): ?string
    {
        return $this->note;
    }

    public function setNote(?string $note): static
    {
        $this->note = $note ?: null;

        return $this;
    }

    /** Frais d'une voiture personnelle : à rembourser tant qu'ils ne sont ni remboursés ni abandonnés. */
    public function normaliser(): static
    {
        if ($this->isVoiture()) {
            $this->tauxKm ??= number_format(self::BAREME_KM, 3, '.', '');
            $this->statut ??= self::A_REMBOURSER;
        } else {
            $this->statut = null;
            $this->regleLe = null;
            $this->tauxKm = null;
        }
        if (!\in_array($this->moyen, ['Minibus du club'], true)) {
            $this->vehicule = null;
        }

        return $this;
    }
}
