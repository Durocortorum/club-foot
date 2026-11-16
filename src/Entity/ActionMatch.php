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
        'C' => ['Centre', 'centre', null],
        'B' => ['But', 'but', true],
        'CO' => ['Corner', 'corner', null],
    ];
    /** Actions de l'adversaire : pas de joueur du club. */
    public const ADVERSES = [
        'AC' => 'Frappe adverse cadrée',
        'AH' => 'Frappe adverse hors cadre',
        'CA' => 'Corner adverse',
        'BA' => 'But adverse',
    ];
    /** Groupes que chaque tablette choisit de suivre (« Mes statistiques ») : nom => codes. */
    public const GROUPES = [
        'Passes' => ['BP', 'MP'],
        'Duels' => ['DG', 'DP'],
        'Contrôles' => ['BC', 'MC'],
        'Frappes' => ['FC', 'FH'],
        'Centres' => ['C'],
        'Corners' => ['CO'],
        'Buts' => ['B'],
        'Adversaire' => ['AC', 'AH', 'CA', 'BA'],
    ];
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
        return isset(self::ACTIONS[$type]) || isset(self::ADVERSES[$type]);
    }

    public static function libelleDe(string $type): string
    {
        return self::ACTIONS[$type][0] ?? self::ADVERSES[$type] ?? $type;
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
        return isset(self::ADVERSES[$this->type]);
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
        $this->periode = max(1, min(4, $periode));
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
            'auteur' => $this->auteur?->getId(), 'par' => $this->auteur ? trim($this->auteur->getPrenom() . ' ' . mb_substr((string) $this->auteur->getNom(), 0, 1) . '.') : null];
    }
}
