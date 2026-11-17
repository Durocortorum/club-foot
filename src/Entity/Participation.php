<?php

namespace App\Entity;

use App\Repository\ParticipationRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Ligne de la feuille de match d'un joueur (table Access « Tbl StatJoueurs2 ») :
 * poste, temps de jeu, note et appréciation de l'éducateur.
 */
#[ORM\Entity(repositoryClass: ParticipationRepository::class)]
#[ORM\UniqueConstraint(columns: ['rencontre_id', 'joueur_id'])]
#[ORM\HasLifecycleCallbacks]
class Participation implements AppartientAuClub
{
    use ClubTrait;

    /**
     * Non enregistré : faux pour une ligne chargée telle quelle de la base, vrai dès que son joueur,
     * son carton ou la minute du carton change. Seuls ces cartons passent le contrôle « sur le terrain
     * à la minute » (Rencontre::validerPresences), pour ne pas bloquer les anciens matchs.
     */
    private bool $aControler = true;

    #[ORM\PostLoad]
    public function apresChargement(): void
    {
        $this->aControler = false;
    }

    public function isAControler(): bool
    {
        return $this->aControler;
    }

    public const POSTES = [
        'Gardien', 'Latéral droit', 'Latéral gauche', 'Défenseur central', 'Défenseur central droit', 'Défenseur central gauche',
        'Milieu défensif', 'Milieu défensif axe', 'Milieu défensif droit', 'Milieu défensif gauche',
        'Milieu relayeur', 'Milieu relayeur droit', 'Milieu relayeur gauche',
        'Milieu offensif', 'Milieu offensif axe', 'Milieu offensif droit', 'Milieu offensif gauche',
        'Milieu excentré droit', 'Milieu excentré gauche', 'Attaquant', 'Non utilisé',
    ];
    /** Codes Access des sanctions. */
    public const CARTONS = [
        'JJ' => 'Jaune (jeu)',
        'JC' => 'Jaune (contestation)',
        'RJ' => 'Rouge (jeu)',
        'RC' => 'Rouge (contestation)',
        'J+R' => 'Jaune puis rouge',
        'BL' => 'Carton blanc (exclusion 10 min)',
    ];
    public const CARTONS_JAUNES = ['JJ', 'JC', 'J+R'];
    public const CARTONS_ROUGES = ['RJ', 'RC', 'J+R'];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'participations')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Rencontre $rencontre = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    #[Assert\NotNull]
    private ?Joueur $joueur = null;

    #[ORM\Column(type: Types::SMALLINT, nullable: true)]
    #[Assert\Range(min: 1, max: 99)]
    private ?int $numero = null;

    #[ORM\Column(length: 40, nullable: true)]
    private ?string $poste = null;

    /**
     * Sur le terrain au coup d'envoi. Coché tout seul pour les numéros 1 à 11, mais modifiable : un titulaire peut
     * porter le n° 23. Sert à la composition, aux minutes et aux statistiques. (Anciennes feuilles : un joueur sans
     * numéro a été coché, comme il comptait déjà pour tout le match.)
     */
    #[ORM\Column(options: ['default' => true])]
    private bool $titulaire = false;

    /** Minutes jouées. */
    #[ORM\Column(type: Types::SMALLINT, nullable: true)]
    #[Assert\Range(min: 0, max: 130)]
    private ?int $tempsJeu = null;

    #[ORM\Column(nullable: true)]
    #[Assert\Range(min: 0, max: 10)]
    private ?float $note = null;

    #[ORM\Column(length: 5, nullable: true)]
    private ?string $carton = null;

    #[ORM\Column(length: 6, nullable: true)]
    #[Assert\Regex('/^\d+(\+\d+)?$/', message: 'Minute invalide.')]
    private ?string $minuteCarton = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $appreciation = null;

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

    public function getJoueur(): ?Joueur
    {
        return $this->joueur;
    }

    public function setJoueur(?Joueur $joueur): static
    {
        $this->aControler = $this->aControler || $joueur !== $this->joueur;
        $this->joueur = $joueur;

        return $this;
    }

    public function getNumero(): ?int
    {
        return $this->numero;
    }

    /** Un nouveau numéro coche ou décoche « titulaire » (1 à 11) ; la case reste modifiable ensuite. */
    public function setNumero(?int $numero): static
    {
        if ($numero !== $this->numero) {
            $this->titulaire = null !== $numero && $numero <= 11;
        }
        $this->numero = $numero;

        return $this;
    }

    public function getTitulaire(): bool
    {
        return $this->titulaire;
    }

    public function setTitulaire(bool $titulaire): static
    {
        $this->titulaire = $titulaire;

        return $this;
    }

    public function getPoste(): ?string
    {
        return $this->poste;
    }

    public function setPoste(?string $poste): static
    {
        $this->poste = $poste;

        return $this;
    }

    public function getTempsJeu(): ?int
    {
        return $this->tempsJeu;
    }

    public function setTempsJeu(?int $tempsJeu): static
    {
        $this->tempsJeu = $tempsJeu;

        return $this;
    }

    public function getNote(): ?float
    {
        return $this->note;
    }

    public function setNote(?float $note): static
    {
        $this->note = $note;

        return $this;
    }

    public function getCarton(): ?string
    {
        return $this->carton;
    }

    public function setCarton(?string $carton): static
    {
        $this->aControler = $this->aControler || $carton !== $this->carton;
        $this->carton = $carton;

        return $this;
    }

    /** Minute du carton telle qu'on l'écrit (« 34 », « 90+2 ») ; un rouge y arrête le temps de jeu. */
    public function getMinuteCarton(): ?string
    {
        return $this->minuteCarton;
    }

    public function setMinuteCarton(?string $minuteCarton): static
    {
        $this->aControler = $this->aControler || ($minuteCarton ?: null) !== $this->minuteCarton;
        $this->minuteCarton = $minuteCarton ?: null;

        return $this;
    }

    public function getCartonLibelle(): ?string
    {
        return self::CARTONS[$this->carton] ?? $this->carton;
    }

    /** « jaune », « rouge » ou « blanc » (exclusion temporaire), pour l'affichage. */
    public function getCouleurCarton(): ?string
    {
        return self::couleurDe($this->carton);
    }

    public static function couleurDe(?string $carton): ?string
    {
        return match (true) {
            \in_array($carton, self::CARTONS_ROUGES, true) => 'rouge',
            \in_array($carton, self::CARTONS_JAUNES, true) => 'jaune',
            'BL' === $carton => 'blanc',
            default => null,
        };
    }

    public function getAppreciation(): ?string
    {
        return $this->appreciation;
    }

    public function setAppreciation(?string $appreciation): static
    {
        $this->appreciation = $appreciation;

        return $this;
    }

    /** Titulaire : la case cochée (d'office pour les numéros 1 à 11), sauf poste « Non utilisé ». */
    public function isTitulaire(): bool
    {
        return $this->titulaire && 'Non utilisé' !== $this->poste;
    }
}
