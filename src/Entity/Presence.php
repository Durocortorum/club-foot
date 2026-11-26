<?php

namespace App\Entity;

use App\Repository\PresenceRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Présences d'un joueur sur une semaine (table Access « Tbl Present2 »).
 * Chaque créneau (voir SemainePresence::CRENEAUX) contient l'équipe avec
 * laquelle il s'est entraîné, un motif d'absence, ou rien s'il n'y avait pas séance.
 */
#[ORM\Entity(repositoryClass: PresenceRepository::class)]
#[ORM\UniqueConstraint(columns: ['semaine_id', 'joueur_id'])]
class Presence implements AppartientAuClub
{
    use ClubTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'presences')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?SemainePresence $semaine = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Joueur $joueur = null;

    /** @var array<string, string> créneau => valeur */
    #[ORM\Column(type: Types::JSON)]
    private array $creneaux = [];

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getSemaine(): ?SemainePresence
    {
        return $this->semaine;
    }

    public function setSemaine(?SemainePresence $semaine): static
    {
        $this->semaine = $semaine;

        return $this;
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

    /** @return array<string, string> */
    public function getCreneaux(): array
    {
        return $this->creneaux;
    }

    /** @param array<string, string|null> $creneaux */
    public function setCreneaux(array $creneaux): static
    {
        $this->creneaux = array_filter(
            array_intersect_key($creneaux, SemainePresence::CRENEAUX),
            fn (?string $v) => null !== $v && '' !== $v
        );

        return $this;
    }

    public function getCreneau(string $creneau): ?string
    {
        return $this->creneaux[$creneau] ?? null;
    }

    /** Forme de la semaine d'entraînement, notée par l'éducateur : libellé et pictogramme. */
    public const FORMES = [
        'top' => ['En grande forme', '🔥'], 'bien' => ['Bien', '🙂'], 'moyen' => ['Moyen', '😐'], 'faible' => ['En difficulté', '😟'],
    ];

    /** Note de la semaine d'entraînement (sur 10, demi-points), avant les convocations du week-end. */
    #[ORM\Column(nullable: true)]
    private ?float $noteSemaine = null;

    #[ORM\Column(length: 10, nullable: true)]
    private ?string $forme = null;

    /** Forme d'après la note de la semaine : 7,5 et plus « En grande forme », 6 « Bien », 5 « Moyen », moins « En difficulté ». */
    public static function formeDeNote(?float $note): ?string
    {
        return match (true) {
            null === $note => null,
            $note >= 7.5 => 'top',
            $note >= 6 => 'bien',
            $note >= 5 => 'moyen',
            default => 'faible',
        };
    }

    public function getNoteSemaine(): ?float
    {
        return $this->noteSemaine;
    }

    /** Note de 0 à 10, arrondie au demi-point ; vide ou invalide : pas de note. */
    public function setNoteSemaine(mixed $note): static
    {
        $note = str_replace(',', '.', trim((string) $note));
        $this->noteSemaine = is_numeric($note) ? round(min(10, max(0, (float) $note)) * 2) / 2 : null;

        return $this;
    }

    public function getForme(): ?string
    {
        return $this->forme;
    }

    public function setForme(?string $forme): static
    {
        $this->forme = isset(self::FORMES[$forme]) ? $forme : null;

        return $this;
    }

    public function getFormeLibelle(): ?string
    {
        return $this->forme ? self::FORMES[$this->forme][0] : null;
    }

    public function getFormeIcone(): ?string
    {
        return $this->forme ? self::FORMES[$this->forme][1] : null;
    }
}
