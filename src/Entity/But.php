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
#[ORM\HasLifecycleCallbacks]
class But implements AppartientAuClub
{
    use ClubTrait;

    /**
     * Non enregistré : faux pour un but chargé tel quel de la base, vrai dès que son buteur, son passeur
     * ou sa minute change. Seuls ces buts passent le contrôle « sur le terrain à la minute »
     * (Rencontre::validerPresences), pour ne pas bloquer les anciens matchs aux feuilles incomplètes.
     */
    private bool $aControler = true;

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

    /** Minutes d'arrêt de jeu après la fin d'une mi-temps : « 45+2 » = minute 45, additionnel 2. */
    #[ORM\Column(type: 'smallint', nullable: true)]
    #[Assert\Range(min: 1, max: 20)]
    private ?int $additionnel = null;

    /** But adverse : numéro de maillot du buteur (les joueurs adverses ne sont pas dans la base). */
    #[ORM\Column(type: 'smallint', nullable: true)]
    #[Assert\Range(min: 1, max: 99)]
    private ?int $numeroAdverse = null;

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

    #[ORM\PostLoad]
    public function apresChargement(): void
    {
        $this->aControler = false;
    }

    public function isAControler(): bool
    {
        return $this->aControler;
    }

    public function setButeur(?Joueur $buteur): static
    {
        $this->aControler = $this->aControler || $buteur !== $this->buteur;
        $this->buteur = $buteur;

        return $this;
    }

    public function getPasseur(): ?Joueur
    {
        return $this->passeur;
    }

    public function setPasseur(?Joueur $passeur): static
    {
        $this->aControler = $this->aControler || $passeur !== $this->passeur;
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

    public function getNumeroAdverse(): ?int
    {
        return $this->numeroAdverse;
    }

    public function setNumeroAdverse(?int $numeroAdverse): static
    {
        $this->numeroAdverse = $numeroAdverse;

        return $this;
    }

    public function getAdditionnel(): ?int
    {
        return $this->additionnel;
    }

    public function setAdditionnel(?int $additionnel): static
    {
        $this->additionnel = $additionnel ?: null;

        return $this;
    }

    /** Minute telle qu'on l'écrit : « 23 », « 45+2 », null si inconnue. */
    public function getTemps(): ?string
    {
        if (null === $this->minute) {
            return null;
        }

        return $this->additionnel ? "{$this->minute}+{$this->additionnel}" : (string) $this->minute;
    }

    /** Inverse de getTemps() : « 45+2 », « 23 » ou null. */
    public function setTemps(?string $temps): static
    {
        $this->aControler = $this->aControler || $temps !== $this->getTemps();
        if (null === $temps || !preg_match('/^(\d+)(?:\+(\d+))?$/', $temps, $m)) {
            return $this->setMinute(null)->setAdditionnel(null);
        }

        return $this->setMinute((int) $m[1])->setAdditionnel(isset($m[2]) ? (int) $m[2] : null);
    }

    /**
     * Tracé du but sur le terrain : départ du ballon et point d'entrée dans le but, en mètres (x de 0 = notre but
     * à 105 = but adverse, y de 0 à 68 dans le sens de notre attaque), comme en direct (ActionMatch::details).
     *
     * @var array{depart: array{0: float, 1: float}, arrivee: array{0: float, 1: float}}|null
     */
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $trace = null;

    public function getTrace(): ?array
    {
        return $this->trace;
    }

    /** Points contrôlés (ramenés sur le terrain, le but et ses filets compris) ; incomplet : pas de tracé. */
    public function setTrace(?array $trace): static
    {
        $point = fn ($p) => \is_array($p) && \count($p) >= 2 && is_numeric($p[0] ?? null) && is_numeric($p[1] ?? null)
            ? [round(max(-3.0, min(108.0, (float) $p[0])), 1), round(max(0.0, min(68.0, (float) $p[1])), 1)] : null;
        $depart = $point($trace['depart'] ?? null);
        $arrivee = $point($trace['arrivee'] ?? null);
        $this->trace = $depart && $arrivee ? ['depart' => $depart, 'arrivee' => $arrivee] : null;

        return $this;
    }

    /** Tracé en texte (champ caché du formulaire de la feuille de match). */
    public function getTraceJson(): string
    {
        return $this->trace ? json_encode($this->trace) : '';
    }

    public function setTraceJson(?string $json): static
    {
        $donnees = $json ? json_decode($json, true) : null;

        return $this->setTrace(\is_array($donnees) ? $donnees : null);
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

    /** Corner, coup franc direct ou indirect, penalty (codes du direct, ActionMatch::SITUATIONS_BUT), d'après la situation. */
    public function getSituationCpa(): ?string
    {
        return ActionMatch::situationDeConditions($this->conditions);
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
