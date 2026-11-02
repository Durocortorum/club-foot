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
class Participation implements AppartientAuClub
{
    use ClubTrait;

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
        'BL' => 'Exclusion temporaire (10 min)',
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

    /** Minutes jouées. */
    #[ORM\Column(type: Types::SMALLINT, nullable: true)]
    #[Assert\Range(min: 0, max: 130)]
    private ?int $tempsJeu = null;

    #[ORM\Column(nullable: true)]
    #[Assert\Range(min: 0, max: 10)]
    private ?float $note = null;

    #[ORM\Column(length: 5, nullable: true)]
    private ?string $carton = null;

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
        $this->joueur = $joueur;

        return $this;
    }

    public function getNumero(): ?int
    {
        return $this->numero;
    }

    public function setNumero(?int $numero): static
    {
        $this->numero = $numero;

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
        $this->carton = $carton;

        return $this;
    }

    public function getCartonLibelle(): ?string
    {
        return self::CARTONS[$this->carton] ?? $this->carton;
    }

    /** « jaune », « rouge » ou « blanc » (exclusion temporaire), pour l'affichage. */
    public function getCouleurCarton(): ?string
    {
        return match (true) {
            \in_array($this->carton, self::CARTONS_ROUGES, true) => 'rouge',
            \in_array($this->carton, self::CARTONS_JAUNES, true) => 'jaune',
            'BL' === $this->carton => 'blanc',
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

    /** Titulaire : numéros 1 à 11, comme sur les feuilles Access. */
    public function isTitulaire(): bool
    {
        return null !== $this->numero && $this->numero <= 11 && 'Non utilisé' !== $this->poste;
    }
}
