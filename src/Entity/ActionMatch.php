<?php

namespace App\Entity;

use App\Repository\ActionMatchRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Action notée pendant le match sur la tablette ou le téléphone (table de statistiques du match) :
 * un joueur, une action (centre, duel, frappe, passe, contrôle…), une zone du terrain et la minute du chrono.
 *
 * Zone : le terrain est découpé en 6 bandes dans la longueur (zx : 0 = devant notre but → 5 = devant le but adverse)
 * et 3 couloirs (zy : 0 = gauche, 1 = axe, 2 = droite, vus dans le sens de notre attaque). Les zones sont donc
 * enregistrées dans le sens de notre attaque, quel que soit le côté du terrain à chaque période.
 */
#[ORM\Entity(repositoryClass: ActionMatchRepository::class)]
#[ORM\UniqueConstraint(columns: ['uuid'])]
#[ORM\Index(columns: ['rencontre_id', 'periode', 'minute'])]
class ActionMatch implements AppartientAuClub
{
    use ClubTrait;

    /** Actions d'un joueur du club : code => [libellé, groupe, réussie ?]. */
    public const ACTIONS = [
        'BP' => ['Bonne passe', 'passe', true],
        'MP' => ['Mauvaise passe', 'passe', false],
        'DG' => ['Duel gagné', 'duel', true],
        'DP' => ['Duel perdu', 'duel', false],
        'BC' => ['Bon contrôle', 'controle', true],
        'MC' => ['Mauvais contrôle', 'controle', false],
        'FC' => ['Frappe cadrée', 'frappe', true],
        'FH' => ['Frappe hors cadre', 'frappe', false],
        'TC' => ['Tête cadrée', 'tete', true],
        'TH' => ['Tête hors cadre', 'tete', false],
        'C' => ['Centre', 'centre', null],
        'B' => ['But', 'but', true],
        'CO' => ['Corner', 'corner', null],
        // Coup franc obtenu (pour l'équipe, comme un corner : sans joueur), placé à l'endroit de la faute.
        'CF' => ['Coup franc', 'coup_franc', null],
        'CJ' => ['Carton jaune', 'carton', null],
        'CR' => ['Carton rouge', 'carton', null],
    ];
    /** Actions de l'adversaire : pas de joueur du club. */
    public const ADVERSES = [
        'AC' => 'Frappe adverse cadrée',
        'AH' => 'Frappe adverse hors cadre',
        'ATC' => 'Tête adverse cadrée',
        'ATH' => 'Tête adverse hors cadre',
        'CA' => 'Corner adverse',
        'CFA' => 'Coup franc adverse',
        'BA' => 'But adverse',
        'JA' => 'Carton jaune adverse',
        'RA' => 'Carton rouge adverse',
    ];
    /**
     * Changement de joueur : le joueur de l'action est celui qui sort ; details : entrant (id, absent si personne n'entre),
     * motif (Choix, Blessure). Une exclusion est un carton rouge (CR).
     * Pas un bouton d'action : il a son propre cadre « Changements » sur la tablette.
     */
    public const CHANGEMENT = 'RP';
    /**
     * Carton blanc, exclusion temporaire (10 minutes) : le joueur sort sans être remplacé et revient seul ; notée depuis le cadre
     * « Changements », reportée sur la feuille de match en carton « BL ».
     */
    public const EXCLUSION_TEMPORAIRE = 'EX';
    /** Hors boutons d'action (cadre « Changements ») : code => libellé. */
    public const HORS_JEU = [self::CHANGEMENT => 'Changement', self::EXCLUSION_TEMPORAIRE => 'Carton blanc (exclusion temporaire, 10 min)'];
    /** Groupes que chaque tablette choisit de suivre (« Mes statistiques ») : nom => codes. */
    public const GROUPES = [
        'Passes' => ['BP', 'MP'],
        'Duels' => ['DG', 'DP'],
        'Contrôles' => ['BC', 'MC'],
        'Frappes' => ['FC', 'FH'],
        'Têtes' => ['TC', 'TH'],
        'Centres' => ['C'],
        'Corners' => ['CO'],
        'Coups francs' => ['CF'],
        'Buts' => ['B'],
        'Cartons' => ['CJ', 'CR'],
        'Changements' => ['RP', 'EX'],
        'Adversaire' => ['AC', 'AH', 'ATC', 'ATH', 'CA', 'CFA', 'BA', 'JA', 'RA'],
    ];
    /**
     * Tirs au but (séance après un match de coupe à égalité) : période 5, details : reussi. Ils ne comptent ni dans le
     * score, ni dans les buts, ni dans les statistiques du match (règle FFF) ; seulement dans le résultat de la séance.
     */
    public const TIRS_AU_BUT = ['TB' => 'Tir au but', 'ATB' => 'Tir au but adverse'];
    public const PERIODE_TAB = 5;
    public const COLONNES = 6;
    public const COULOIRS = 3;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** Identifiant donné par la tablette : une action envoyée deux fois (réseau coupé) n'est enregistrée qu'une fois. */
    #[ORM\Column(length: 36)]
    private string $uuid;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Rencontre $rencontre;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?Joueur $joueur = null;

    #[ORM\Column(length: 3)]
    private string $type;

    #[ORM\Column(type: 'smallint')]
    private int $periode = 1;

    #[ORM\Column(type: 'smallint')]
    private int $minute = 0;

    #[ORM\Column(type: 'smallint')]
    private int $zx = 0;

    #[ORM\Column(type: 'smallint')]
    private int $zy = 1;

    #[ORM\Column]
    private \DateTimeImmutable $notee;

    /**
     * Tracé d'un but (B ou BA) : place du ballon au tir et point d'entrée dans le but, en mètres sur le terrain
     * (x de 0 = notre but à 105 = but adverse, y de 0 = gauche à 68 = droite dans le sens de notre attaque),
     * et la façon de marquer (tir, tête, csc).
     *
     * @var array{depart?: array{0: float, 1: float}, arrivee?: array{0: float, 1: float}, geste?: string}|null
     */
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $details = null;

    public const GESTES_BUT = ['tir' => 'Tir', 'tete' => 'Tête', 'csc' => 'Contre son camp'];
    /**
     * D'où vient un but (B ou BA) quand ce n'est pas une action de jeu : coup de pied arrêté. Le passeur décisif d'un but
     * sur corner ou sur coup franc indirect est le joueur qui l'a tiré. Reporté sur la feuille de match (« Situation »).
     */
    public const SITUATIONS_BUT = ['corner' => 'Corner', 'cod' => 'Corner direct', 'cfd' => 'Coup franc direct', 'cfi' => 'Coup franc indirect', 'penalty' => 'Penalty'];

    /** Situation du direct d'après la « Situation » écrite sur la feuille de match (Corner droit, CF direct, Pénalty…). */
    public static function situationDeConditions(?string $conditions): ?string
    {
        $c = mb_strtolower(trim((string) $conditions));

        return match (true) {
            str_starts_with($c, 'corner direct') => 'cod',
            str_starts_with($c, 'corner') => 'corner',
            str_contains($c, 'indirect') && (str_starts_with($c, 'cf') || str_starts_with($c, 'coup franc')) => 'cfi',
            str_starts_with($c, 'cf') || str_starts_with($c, 'coup franc') => 'cfd',
            str_starts_with($c, 'pénalty') || str_starts_with($c, 'penalty') || str_starts_with($c, 'penalti') => 'penalty',
            default => null,
        };
    }

    /**
     * « Situation » de la feuille de match pour une situation du direct ; corner : côté d'où il est tiré (vu par l'équipe
     * qui attaque) quand le départ du tir est connu.
     *
     * @param array{0: float, 1: float}|null $depart
     */
    public static function conditionsDeSituation(string $situation, ?array $depart, bool $adverse): ?string
    {
        return match ($situation) {
            'corner' => null === $depart ? 'Corner' : (($depart[1] < 34) !== $adverse ? 'Corner gauche' : 'Corner droit'),
            // Corner direct : marqué du drapeau, sans passeur.
            'cod' => 'Corner direct',
            'cfd' => 'CF direct',
            'cfi' => 'CF indirect',
            'penalty' => 'Pénalty',
            default => null,
        };
    }

    public function getDetails(): ?array
    {
        return $this->details;
    }

    /** Sans place sur le terrain (feuille de match, saisie en direct sans zone) : comptée, mais pas sur la carte. */
    public function isHorsCarte(): bool
    {
        return true === ($this->details['horsCarte'] ?? null);
    }

    /** Tracé contrôlé : seulement pour un but, points ramenés sur le terrain (le but et ses filets compris). */
    public function setDetails(?array $details): static
    {
        // Changement : joueur entrant et motif.
        if (self::CHANGEMENT === ($this->type ?? null)) {
            // Sans entrant : le joueur sort sans être remplacé (plus de remplaçant), l'équipe joue à un de moins.
            $entrant = is_numeric($details['entrant'] ?? null) ? (int) $details['entrant'] : 0;
            $this->details = array_filter(['entrant' => $entrant > 0 ? $entrant : null, 'motif' => 'Blessure' === ($details['motif'] ?? null) ? 'Blessure' : 'Choix'], fn ($v) => null !== $v);

            return $this;
        }
        // Tir au but : marqué ou raté, rien d'autre.
        if (isset(self::TIRS_AU_BUT[$this->type ?? ''])) {
            // Ordre dans la séance (1, 2, 3…) : les tirs s'affichent dans l'ordre où ils ont été tirés.
            $ordre = is_numeric($details['ordre'] ?? null) ? max(1, min(99, (int) $details['ordre'])) : null;
            $this->details = array_filter(['reussi' => true === ($details['reussi'] ?? null), 'ordre' => $ordre], fn ($v) => null !== $v);

            return $this;
        }
        // « Hors carte » : venue de la feuille de match sans place sur le terrain (pas dessinée sur la carte).
        $horsCarte = true === ($details['horsCarte'] ?? null);
        $point = fn ($p) => \is_array($p) && \count($p) >= 2 && is_numeric($p[0]) && is_numeric($p[1])
            ? [round(max(-3.0, min(108.0, (float) $p[0])), 1), round(max(0.0, min(68.0, (float) $p[1])), 1)] : null;
        if (null === $details || !\in_array($this->type ?? null, ['B', 'BA'], true)) {
            // Autres actions : endroit précis touché sur le terrain (mètres dans le sens de notre attaque), facultatif.
            $endroit = $point($details['point'] ?? null);
            $this->details = $endroit ? ['point' => [min(105.0, max(0.0, $endroit[0])), $endroit[1]]] : ($horsCarte ? ['horsCarte' => true] : null);

            return $this;
        }
        $propre = array_filter([
            'depart' => $point($details['depart'] ?? null),
            'arrivee' => $point($details['arrivee'] ?? null),
            'geste' => isset(self::GESTES_BUT[$details['geste'] ?? '']) ? $details['geste'] : null,
            // Passeur décisif (id du joueur ; contrôlé par le contrôleur : joueur du match, autre que le buteur).
            'passeur' => 'B' === $this->type && 'cod' !== ($details['situation'] ?? null) && is_numeric($details['passeur'] ?? null) && (int) $details['passeur'] > 0 ? (int) $details['passeur'] : null,
            // Corner, coup franc, penalty (absent : action de jeu).
            'situation' => isset(self::SITUATIONS_BUT[$details['situation'] ?? '']) ? $details['situation'] : null,
            // Coup franc indirect : endroit d'où il est tiré (le départ est celui de la reprise du buteur).
            'coupFranc' => 'cfi' === ($details['situation'] ?? null) && ($cf = $point($details['coupFranc'] ?? null)) ? [min(105.0, max(0.0, $cf[0])), $cf[1]] : null,
            'horsCarte' => $horsCarte && !isset($details['depart']) ? true : null,
        ], fn ($v) => null !== $v);
        // Penalty : tiré du point de penalty, même sans place notée.
        if ('penalty' === ($propre['situation'] ?? null) && !isset($propre['depart'])) {
            $nous = 'B' === $this->type;
            $propre = ['depart' => [$nous ? 94.0 : 11.0, 34.0], 'arrivee' => [$nous ? 105.0 : 0.0, 34.0]] + $propre;
            unset($propre['horsCarte']);
        }
        $this->details = $propre ?: null;

        return $this;
    }

    /** Qui l'a notée (plusieurs tablettes au même match) : seul lui la corrige pendant le match. */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?Utilisateur $auteur = null;

    public function getAuteur(): ?Utilisateur
    {
        return $this->auteur;
    }

    public function setAuteur(?Utilisateur $auteur): static
    {
        $this->auteur = $auteur;

        return $this;
    }

    /** Pendant le match, une action se corrige sur la tablette de celui qui l'a notée (même compte). */
    public function modifiablePar(Utilisateur $utilisateur): bool
    {
        return null === $this->auteur || $this->auteur->getId() === $utilisateur->getId();
    }

    public function __construct(Rencontre $rencontre, string $uuid)
    {
        $this->rencontre = $rencontre;
        $this->uuid = $uuid;
        $this->notee = new \DateTimeImmutable();
    }

    public static function existe(string $type): bool
    {
        return isset(self::ACTIONS[$type]) || isset(self::ADVERSES[$type]) || isset(self::HORS_JEU[$type]) || isset(self::TIRS_AU_BUT[$type]);
    }

    /** Action d'un joueur du club (bouton d'action, changement, exclusion temporaire). */
    public static function deJoueur(string $type): bool
    {
        return isset(self::ACTIONS[$type]) || isset(self::HORS_JEU[$type]) || 'TB' === $type;
    }

    public static function libelleDe(string $type): string
    {
        return self::ACTIONS[$type][0] ?? self::ADVERSES[$type] ?? self::HORS_JEU[$type] ?? self::TIRS_AU_BUT[$type] ?? $type;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUuid(): string
    {
        return $this->uuid;
    }

    public function getRencontre(): Rencontre
    {
        return $this->rencontre;
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

    public function getType(): string
    {
        return $this->type;
    }

    public function setType(string $type): static
    {
        $this->type = $type;

        return $this;
    }

    public function isAdverse(): bool
    {
        return isset(self::ADVERSES[$this->type]) || 'ATB' === $this->type;
    }

    public function isTirAuBut(): bool
    {
        return isset(self::TIRS_AU_BUT[$this->type]);
    }

    /** Tir au but marqué. */
    public function isReussi(): bool
    {
        return true === ($this->details['reussi'] ?? null);
    }

    public function getLibelle(): string
    {
        return self::libelleDe($this->type);
    }

    public function getPeriode(): int
    {
        return $this->periode;
    }

    public function getMinute(): int
    {
        return $this->minute;
    }

    public function placer(int $periode, int $minute, int $zx, int $zy): static
    {
        $this->periode = max(1, min(self::PERIODE_TAB, $periode));
        $this->minute = max(0, min(130, $minute));
        $this->zx = max(0, min(self::COLONNES - 1, $zx));
        $this->zy = max(0, min(self::COULOIRS - 1, $zy));

        return $this;
    }

    public function getZx(): int
    {
        return $this->zx;
    }

    public function getZy(): int
    {
        return $this->zy;
    }

    /** Pour la tablette (journal du match, reprise après rechargement). */
    public function enTableau(): array
    {
        return ['uuid' => $this->uuid, 'joueur' => $this->joueur?->getId(), 'type' => $this->type, 'periode' => $this->periode,
            'minute' => $this->minute, 'zx' => $this->zx, 'zy' => $this->zy,
            'details' => $this->details,
            'auteur' => $this->auteur?->getId(), 'par' => $this->auteur ? trim($this->auteur->getPrenom() . ' ' . mb_substr((string) $this->auteur->getNom(), 0, 1) . '.') : null];
    }
}
