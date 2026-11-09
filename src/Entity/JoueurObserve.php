<?php

namespace App\Entity;

use App\Repository\JoueurObserveRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Joueur d'un autre club suivi par le recrutement (table Access « Tbl JoueursObserves »),
 * avec la fiche d'évaluation de son poste (tables « Tbl Attaquants », « Tbl DefenseurLateraux »,
 * « Tbl MilieuxExcentres »).
 */
#[ORM\Entity(repositoryClass: JoueurObserveRepository::class)]
class JoueurObserve implements AppartientAuClub
{
    use ClubTrait;

    public const NIVEAUX = ['Départemental', 'Régional', 'National'];
    public const POSTES = [
        'Gardien', 'Défenseur central', 'Défenseur latéral droit', 'Défenseur latéral gauche', 'Milieu défensif',
        'Milieu axial', 'Milieu offensif', 'Milieu excentré droit', 'Milieu excentré gauche', 'Attaquant',
    ];

    /** Critères d'évaluation par type de poste, repris des fiches Access. */
    public const GRILLES = [
        'attaquant' => [
            'libelle' => 'Attaquant',
            'criteres' => ['qualitesDeplacement' => 'Qualités de déplacement', 'gesteFinition' => 'Geste de finition', 'qualiteDuels' => 'Qualité dans les duels'],
        ],
        'lateral' => [
            'libelle' => 'Défenseur latéral',
            'criteres' => [
                'qualitesDuel' => 'Qualités dans le duel', 'participeOffensive' => 'Participation offensive', 'volume' => 'Volume de jeu',
                'qualiteCentres' => 'Qualité des centres', 'vitesse' => 'Vitesse',
            ],
        ],
        'excentre' => [
            'libelle' => 'Milieu excentré',
            'criteres' => [
                'percussionVitesse' => 'Percussion, vitesse', 'qualiteDeplacement' => 'Qualité de déplacement',
                'qualiteDernierePasse' => 'Qualité de dernière passe', 'volume' => 'Volume de jeu',
            ],
        ],
        'general' => [
            'libelle' => 'Autres postes',
            'criteres' => ['pointsForts' => 'Points forts', 'pointsAmeliorer' => 'Points à améliorer'],
        ],
    ];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 50)]
    #[Assert\NotBlank]
    private ?string $nom = null;

    #[ORM\Column(length: 50, nullable: true)]
    private ?string $prenom = null;

    /** Âge au moment de l'observation (Access ne gardait pas la date de naissance). */
    #[ORM\Column(type: Types::SMALLINT, nullable: true)]
    #[Assert\Range(min: 5, max: 40)]
    private ?int $age = null;

    #[ORM\Column(length: 40, nullable: true)]
    private ?string $poste = null;

    #[ORM\Column(length: 80, nullable: true)]
    private ?string $clubActuel = null;

    #[ORM\Column(length: 30, nullable: true)]
    private ?string $niveau = null;

    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $dateObservation = null;

    /** @var array<string, string> critère => appréciation */
    #[ORM\Column(type: Types::JSON)]
    private array $evaluation = [];

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $commentaire = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getNom(): ?string
    {
        return $this->nom;
    }

    /** Nom de famille, toujours en majuscules. */
    public function setNom(?string $nom): static
    {
        $this->nom = null === $nom ? null : mb_strtoupper(trim($nom));

        return $this;
    }

    public function getPrenom(): ?string
    {
        return $this->prenom;
    }

    public function setPrenom(?string $prenom): static
    {
        $this->prenom = $prenom;

        return $this;
    }

    public function getNomComplet(): string
    {
        return trim($this->prenom . ' ' . $this->nom);
    }

    public function getAge(): ?int
    {
        return $this->age;
    }

    public function setAge(?int $age): static
    {
        $this->age = $age;

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

    public function getClubActuel(): ?string
    {
        return $this->clubActuel;
    }

    public function setClubActuel(?string $clubActuel): static
    {
        $this->clubActuel = null === $clubActuel ? null : mb_strtoupper(trim($clubActuel));

        return $this;
    }

    public function getNiveau(): ?string
    {
        return $this->niveau;
    }

    public function setNiveau(?string $niveau): static
    {
        $this->niveau = $niveau;

        return $this;
    }

    public function getDateObservation(): ?\DateTimeImmutable
    {
        return $this->dateObservation;
    }

    public function setDateObservation(?\DateTimeImmutable $dateObservation): static
    {
        $this->dateObservation = $dateObservation;

        return $this;
    }

    /** @return array<string, string> */
    public function getEvaluation(): array
    {
        return $this->evaluation;
    }

    /** @param array<string, string|null> $evaluation */
    public function setEvaluation(array $evaluation): static
    {
        $this->evaluation = array_filter($evaluation, fn (?string $v) => null !== $v && '' !== trim($v));

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

    /** Grille d'évaluation correspondant au poste (clé de GRILLES). */
    public function getGrille(): string
    {
        $poste = mb_strtolower((string) $this->poste);

        return match (true) {
            str_contains($poste, 'attaquant') => 'attaquant',
            str_contains($poste, 'latéral'), str_contains($poste, 'lateral') => 'lateral',
            str_contains($poste, 'excentré'), str_contains($poste, 'excentre') => 'excentre',
            default => 'general',
        };
    }
}
