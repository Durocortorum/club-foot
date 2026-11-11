<?php

namespace App\Entity;

use App\Repository\RessentiRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Autoévaluation d'un joueur après un entraînement ou un match : effort ressenti (RPE, échelle de Borg
 * CR-10, de 1 à 10) et durée. La charge de la séance est RPE × minutes (méthode de Foster).
 */
#[ORM\Entity(repositoryClass: RessentiRepository::class)]
#[ORM\UniqueConstraint(name: 'ressenti_joueur_entrainement', columns: ['joueur_id', 'entrainement_id'])]
#[ORM\UniqueConstraint(name: 'ressenti_joueur_rencontre', columns: ['joueur_id', 'rencontre_id'])]
class Ressenti implements AppartientAuClub
{
    use ClubTrait;

    /** Échelle affichée au joueur. */
    public const ECHELLE = [
        1 => 'Très, très facile', 2 => 'Facile', 3 => 'Modéré', 4 => 'Un peu dur', 5 => 'Dur',
        6 => 'Dur +', 7 => 'Très dur', 8 => 'Très dur +', 9 => 'Presque au maximum', 10 => 'Maximum',
    ];
    /** Combien de jours après la séance le joueur peut encore la noter. */
    public const DELAI_JOURS = 3;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Joueur $joueur;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'CASCADE')]
    private ?Entrainement $entrainement = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'CASCADE')]
    private ?Rencontre $rencontre = null;

    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    private \DateTimeImmutable $date;

    #[ORM\Column(type: Types::SMALLINT)]
    private int $rpe;

    /** Minutes d'effort (durée de la séance, ou temps joué). */
    #[ORM\Column(type: Types::SMALLINT)]
    private int $duree;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $commentaire = null;

    #[ORM\Column]
    private \DateTimeImmutable $saisiLe;

    public function __construct(Joueur $joueur, Entrainement|Rencontre $seance, int $rpe, int $duree, ?string $commentaire = null)
    {
        $this->joueur = $joueur;
        if ($seance instanceof Entrainement) {
            $this->entrainement = $seance;
        } else {
            $this->rencontre = $seance;
        }
        $this->date = $seance->getDate();
        $this->modifier($rpe, $duree, $commentaire);
    }

    public function modifier(int $rpe, int $duree, ?string $commentaire): static
    {
        $this->rpe = max(1, min(10, $rpe));
        $this->duree = max(1, min(300, $duree));
        $this->commentaire = $commentaire ? mb_substr(trim($commentaire), 0, 255) : null;
        $this->saisiLe = new \DateTimeImmutable();

        return $this;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getJoueur(): Joueur
    {
        return $this->joueur;
    }

    public function getEntrainement(): ?Entrainement
    {
        return $this->entrainement;
    }

    public function getRencontre(): ?Rencontre
    {
        return $this->rencontre;
    }

    public function getDate(): \DateTimeImmutable
    {
        return $this->date;
    }

    public function getRpe(): int
    {
        return $this->rpe;
    }

    public function getDuree(): int
    {
        return $this->duree;
    }

    /** Charge de la séance : RPE × minutes (unités arbitraires). */
    public function getCharge(): int
    {
        return $this->rpe * $this->duree;
    }

    public function getCommentaire(): ?string
    {
        return $this->commentaire;
    }

    public function getSaisiLe(): \DateTimeImmutable
    {
        return $this->saisiLe;
    }

    public function getLibelle(): string
    {
        return self::ECHELLE[$this->rpe];
    }

    /** Durée d'une séance en minutes : horaires de l'entraînement (90 min sans heure de fin), durée de match de la catégorie. */
    public static function dureeDe(Entrainement|Rencontre $seance): int
    {
        if ($seance instanceof Rencontre) {
            return $seance->getCategorie()?->getDureeMatch() ?? Categorie::dureeParDefaut($seance->getCategorie()?->getNom()) ?? 90;
        }
        if ($seance->getHeureFin() && preg_match('/^(\d{1,2}):(\d{2})$/', $seance->getHeureDebut(), $d) && preg_match('/^(\d{1,2}):(\d{2})$/', $seance->getHeureFin(), $f)) {
            $minutes = ((int) $f[1] * 60 + (int) $f[2]) - ((int) $d[1] * 60 + (int) $d[2]);
            if ($minutes > 0) {
                return $minutes;
            }
        }

        return 90;
    }
}
