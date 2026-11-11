<?php

namespace App\Entity;

use App\Repository\BlessureRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Blessure suivie par l'infirmerie (table Access « tbl Infirmerie »).
 */
#[ORM\Entity(repositoryClass: BlessureRepository::class)]
#[Assert\Expression('!this.getDate() or !this.getDateReprise() or this.getDateReprise() >= this.getDate()', message: 'La reprise ne peut pas précéder la blessure.')]
#[Assert\Expression('!this.getDateRepriseMatch() or ((!this.getDate() or this.getDateRepriseMatch() >= this.getDate()) and (!this.getDateReprise() or this.getDateRepriseMatch() >= this.getDateReprise()))', message: 'La reprise des matchs ne peut pas précéder la blessure ni la reprise de l\'entraînement.')]
class Blessure implements AppartientAuClub
{
    use ClubTrait;

    public const LIEUX = ['Entrainement', 'Match', 'Hors foot'];
    public const UNITES_ARRET = ['jours', 'semaines', 'mois'];
    public const MEMBRES =['Membre inférieur', 'Membre supérieur', 'Tronc', 'Tête'];
    public const TYPES = [
        'Contracture', 'Contusion', 'Courbatures', 'Déchirure', 'Douleurs', 'Elongation', 'Entorse', 'Entorse bénigne',
        'Fracture', 'Inflammation', 'Luxation', 'Périostite', 'Pubalgie', 'Tendinite',
    ];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    #[Assert\NotNull]
    private ?Joueur $joueur = null;

    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $date = null;

    /** Reprise de l'entraînement (reprise sportive, avec le groupe). */
    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $dateReprise = null;

    /** Reprise des matchs (apte à la compétition) ; vide : en même temps que l'entraînement. */
    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $dateRepriseMatch = null;

    #[ORM\Column(length: 60, nullable: true)]
    private ?string $type = null;

    /** Partie du corps touchée (ex. : « Ligament croisé antérieur »). */
    #[ORM\Column(length: 80, nullable: true)]
    private ?string $zone = null;

    /** Côté et région (ex. : « Genou gauche »). */
    #[ORM\Column(length: 40, nullable: true)]
    private ?string $localisation = null;

    #[ORM\Column(length: 30, nullable: true)]
    private ?string $membre = null;

    #[ORM\Column(length: 20, nullable: true)]
    private ?string $lieu = null;

    #[ORM\Column(nullable: true)]
    private ?bool $avecArret = null;

    /** Arrêt envisagé à la blessure : nombre de jours, semaines ou mois (voir uniteArret). */
    #[ORM\Column(type: Types::SMALLINT, nullable: true)]
    #[Assert\Range(min: 1, max: 365, notInRangeMessage: 'Entre {{ min }} et {{ max }}.')]
    private ?int $arretPrevu = null;

    #[ORM\Column(length: 10, nullable: true)]
    #[Assert\Choice(choices: self::UNITES_ARRET)]
    private ?string $uniteArret = 'semaines';

    /** Équipe du joueur au moment de la blessure. */
    #[ORM\Column(length: 30, nullable: true)]
    private ?string $equipe = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $commentaire = null;

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

    public function getDateReprise(): ?\DateTimeImmutable
    {
        return $this->dateReprise;
    }

    public function setDateReprise(?\DateTimeImmutable $dateReprise): static
    {
        $this->dateReprise = $dateReprise;

        return $this;
    }

    public function getDateRepriseMatch(): ?\DateTimeImmutable
    {
        return $this->dateRepriseMatch;
    }

    public function setDateRepriseMatch(?\DateTimeImmutable $dateRepriseMatch): static
    {
        $this->dateRepriseMatch = $dateRepriseMatch;

        return $this;
    }

    /** Date à laquelle le joueur peut rejouer en match : la reprise des matchs, sinon celle de l'entraînement. */
    public function getRepriseMatchEffective(): ?\DateTimeImmutable
    {
        return $this->dateRepriseMatch ?? $this->dateReprise;
    }

    /**
     * Où en est le joueur aujourd'hui : « arret » (ni entraînement ni match), « entrainement » (reprise
     * de l'entraînement, pas encore des matchs) ou « apte » (rejoue en match).
     */
    public function getStatut(): string
    {
        $aujourdhui = new \DateTimeImmutable('today');
        if (null === $this->dateReprise || $this->dateReprise > $aujourdhui) {
            return 'arret';
        }

        return null !== $this->dateRepriseMatch && $this->dateRepriseMatch > $aujourdhui ? 'entrainement' : 'apte';
    }

    public function getType(): ?string
    {
        return $this->type;
    }

    public function setType(?string $type): static
    {
        $this->type = $type;

        return $this;
    }

    public function getZone(): ?string
    {
        return $this->zone;
    }

    public function setZone(?string $zone): static
    {
        $this->zone = $zone;

        return $this;
    }

    public function getLocalisation(): ?string
    {
        return $this->localisation;
    }

    public function setLocalisation(?string $localisation): static
    {
        $this->localisation = $localisation;

        return $this;
    }

    public function getMembre(): ?string
    {
        return $this->membre;
    }

    public function setMembre(?string $membre): static
    {
        $this->membre = $membre;

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

    public function isAvecArret(): ?bool
    {
        return $this->avecArret;
    }

    public function setAvecArret(?bool $avecArret): static
    {
        $this->avecArret = $avecArret;

        return $this;
    }

    public function getEquipe(): ?string
    {
        return $this->equipe;
    }

    public function setEquipe(?string $equipe): static
    {
        $this->equipe = null === $equipe ? null : mb_strtoupper(trim($equipe));

        return $this;
    }

    public function getCommentaire(): ?string
    {
        return $this->commentaire;
    }

    public function setCommentaire(?string $commentaire): static
    {
        $this->commentaire = $commentaire;

        return $this;
    }

    public function getArretPrevu(): ?int
    {
        return $this->arretPrevu;
    }

    public function setArretPrevu(?int $arretPrevu): static
    {
        $this->arretPrevu = $arretPrevu;

        return $this;
    }

    public function getUniteArret(): ?string
    {
        return $this->uniteArret;
    }

    public function setUniteArret(?string $uniteArret): static
    {
        $this->uniteArret = $uniteArret;

        return $this;
    }

    /** « 10 jours », « 1 semaine », « 3 mois » ; null sans arrêt envisagé. */
    public function getArretPrevuLibelle(): ?string
    {
        if (!$this->arretPrevu) {
            return null;
        }
        $unite = $this->uniteArret ?? 'jours';
        if ('mois' !== $unite && 1 === $this->arretPrevu) {
            $unite = rtrim($unite, 's');
        }

        return $this->arretPrevu . ' ' . $unite;
    }

    /** Reprise envisagée : date de la blessure + arrêt envisagé. */
    public function getRepriseEnvisagee(): ?\DateTimeImmutable
    {
        if (!$this->date || !$this->arretPrevu) {
            return null;
        }
        if ('mois' === $this->uniteArret) {
            // 31 janvier + 1 mois : 28 (ou 29) février, pas début mars.
            $d = $this->date->modify(\sprintf('first day of +%d months', $this->arretPrevu));

            return $d->setDate((int) $d->format('Y'), (int) $d->format('m'), min((int) $this->date->format('d'), (int) $d->format('t')));
        }

        return $this->date->modify(\sprintf('+%d days', $this->arretPrevu * ('semaines' === $this->uniteArret ? 7 : 1)));
    }

    /** Joueur pas encore apte aux matchs : arrêté, ou repris l'entraînement seulement. */
    public function isEnCours(): bool
    {
        return 'apte' !== $this->getStatut();
    }

    /** Durée d'indisponibilité en jours, si les deux dates sont connues. */
    public function getDuree(): ?int
    {
        return $this->date && $this->dateReprise ? $this->date->diff($this->dateReprise)->days : null;
    }

    public function getLibelle(): string
    {
        return implode(' – ', array_filter([$this->type, $this->zone, $this->localisation])) ?: 'Blessure';
    }
}
