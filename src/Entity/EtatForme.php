<?php

namespace App\Entity;

use App\Repository\EtatFormeRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * État de forme du jour, renseigné par le joueur (ou sa famille) en quelques secondes : fatigue, sommeil, douleurs,
 * motivation, de 0 à 10. L'éducateur en tire une vue de son groupe pour adapter la séance et prévenir les blessures.
 * Un seul état par joueur et par jour (corrigeable le jour même).
 */
#[ORM\Entity(repositoryClass: EtatFormeRepository::class)]
#[ORM\UniqueConstraint(columns: ['joueur_id', 'jour'])]
class EtatForme implements AppartientAuClub
{
    use ClubTrait;

    /**
     * Critères : libellé, bornes de l'échelle (0 et 10), pictogramme, et sens (true : plus haut = mieux).
     * Seuils : vert, orange, rouge (voir niveau()).
     */
    public const CRITERES = [
        'fatigue' => ['Fatigue', 'Aucune', 'Très forte', '🔋', false],
        'sommeil' => ['Sommeil', 'Mauvais', 'Excellent', '🛏️', true],
        'douleurs' => ['Douleurs', 'Aucune', 'Importantes', '🩹', false],
        'motivation' => ['Motivation', 'Faible', 'Très élevée', '🔥', true],
    ];
    public const ETATS = ['forme' => 'En forme', 'surveiller' => 'À surveiller', 'fatigue' => 'Fatigué'];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Joueur $joueur;

    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    private \DateTimeImmutable $jour;

    #[ORM\Column(type: Types::SMALLINT)]
    private int $fatigue = 0;

    #[ORM\Column(type: Types::SMALLINT)]
    private int $sommeil = 10;

    #[ORM\Column(type: Types::SMALLINT)]
    private int $douleurs = 0;

    #[ORM\Column(type: Types::SMALLINT)]
    private int $motivation = 10;

    #[ORM\Column(length: 200, nullable: true)]
    private ?string $commentaire = null;

    /** « Parent : Sophie MARTIN » ou « Joueur ». */
    #[ORM\Column(length: 120, nullable: true)]
    private ?string $saisiPar = null;

    #[ORM\Column]
    private \DateTimeImmutable $saisiLe;

    public function __construct(Joueur $joueur, \DateTimeImmutable $jour)
    {
        $this->joueur = $joueur;
        $this->jour = $jour->setTime(0, 0);
        $this->saisiLe = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getJoueur(): Joueur
    {
        return $this->joueur;
    }

    public function getJour(): \DateTimeImmutable
    {
        return $this->jour;
    }

    /** Valeur d'un critère (« fatigue »…), de 0 à 10. */
    public function valeur(string $critere): int
    {
        return match ($critere) {
            'fatigue' => $this->fatigue, 'sommeil' => $this->sommeil, 'douleurs' => $this->douleurs, 'motivation' => $this->motivation,
            default => throw new \InvalidArgumentException($critere),
        };
    }

    /** @param array<string, mixed> $valeurs critère => valeur (0 à 10) ; les critères absents ne changent pas */
    public function renseigner(array $valeurs, ?string $commentaire, ?string $saisiPar): static
    {
        foreach (array_keys(self::CRITERES) as $c) {
            if (isset($valeurs[$c]) && is_numeric($valeurs[$c])) {
                $this->$c = max(0, min(10, (int) $valeurs[$c]));
            }
        }
        $commentaire = trim((string) $commentaire);
        $this->commentaire = '' === $commentaire ? null : mb_substr($commentaire, 0, 200);
        $this->saisiPar = $saisiPar;
        $this->saisiLe = new \DateTimeImmutable();

        return $this;
    }

    /**
     * Couleur d'un critère : « vert », « orange » ou « rouge ». Fatigue : 0-4 / 5-6 / 7-10 ; douleurs : 0-2 / 3-5 / 6-10 ;
     * sommeil et motivation : 7-10 / 5-6 / 0-4.
     */
    public function niveau(string $critere): string
    {
        $v = $this->valeur($critere);

        return match ($critere) {
            'fatigue' => $v <= 4 ? 'vert' : ($v <= 6 ? 'orange' : 'rouge'),
            'douleurs' => $v <= 2 ? 'vert' : ($v <= 5 ? 'orange' : 'rouge'),
            default => $v >= 7 ? 'vert' : ($v >= 5 ? 'orange' : 'rouge'),
        };
    }

    /** État d'ensemble : « fatigue » si un critère est au rouge, « surveiller » s'il y en a à l'orange, sinon « forme ». */
    public function getEtat(): string
    {
        $niveaux = array_map(fn (string $c) => $this->niveau($c), array_keys(self::CRITERES));

        return \in_array('rouge', $niveaux, true) ? 'fatigue' : (\in_array('orange', $niveaux, true) ? 'surveiller' : 'forme');
    }

    public function getEtatLibelle(): string
    {
        return self::ETATS[$this->getEtat()];
    }

    public function getCommentaire(): ?string
    {
        return $this->commentaire;
    }

    public function getSaisiPar(): ?string
    {
        return $this->saisiPar;
    }

    public function getSaisiLe(): \DateTimeImmutable
    {
        return $this->saisiLe;
    }
}
