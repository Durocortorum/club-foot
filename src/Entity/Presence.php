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
}
