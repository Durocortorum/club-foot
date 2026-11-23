<?php

namespace App\Entity;

use App\Repository\ObjectifJoueurRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Objectif individuel de progression d'un joueur pour une saison (« Améliorer ma finition »), fixé par l'entraîneur :
 * thème, pourcentage d'atteinte mis à jour au fil de la saison (l'historique sert à la progression mois par mois).
 */
#[ORM\Entity(repositoryClass: ObjectifJoueurRepository::class)]
#[ORM\Index(columns: ['saison'])]
class ObjectifJoueur implements AppartientAuClub
{
    use ClubTrait;

    /** Thèmes proposés : libellé et pictogramme. */
    public const THEMES = [
        'finition' => ['Finition', '⚽'], 'technique' => ['Technique', '🎯'], 'physique' => ['Physique', '🏃'],
        'defense' => ['Jeu défensif', '🛡️'], 'tactique' => ['Tactique', '🧠'], 'mental' => ['Mental, attitude', '💪'],
        'autre' => ['Autre', '⭐'],
    ];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Joueur $joueur;

    /** Saison « 2026/2027 ». */
    #[ORM\Column(length: 9)]
    private string $saison;

    #[ORM\Column(length: 120)]
    private string $titre = '';

    #[ORM\Column(length: 20)]
    private string $theme = 'autre';

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $description = null;

    /** Atteinte de l'objectif, de 0 à 100 %. */
    #[ORM\Column]
    private int $progression = 0;

    /** @var list<array{0: string, 1: int}> date (AAAA-MM-JJ) et pourcentage à chaque mise à jour */
    #[ORM\Column(type: Types::JSON)]
    private array $historique = [];

    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $echeance = null;

    #[ORM\Column]
    private \DateTimeImmutable $creeLe;

    #[ORM\Column(length: 170, nullable: true)]
    private ?string $creeParNom = null;

    public function __construct(Joueur $joueur, string $saison)
    {
        $this->joueur = $joueur;
        $this->saison = $saison;
        $this->creeLe = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getJoueur(): Joueur
    {
        return $this->joueur;
    }

    public function getSaison(): string
    {
        return $this->saison;
    }

    public function getTitre(): string
    {
        return $this->titre;
    }

    public function setTitre(?string $titre): static
    {
        $this->titre = mb_substr(trim((string) $titre), 0, 120);

        return $this;
    }

    public function getTheme(): string
    {
        return $this->theme;
    }

    public function setTheme(?string $theme): static
    {
        $this->theme = isset(self::THEMES[$theme]) ? $theme : 'autre';

        return $this;
    }

    public function getThemeLibelle(): string
    {
        return self::THEMES[$this->theme][0];
    }

    public function getIcone(): string
    {
        return self::THEMES[$this->theme][1];
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): static
    {
        $description = trim((string) $description);
        $this->description = '' === $description ? null : mb_substr($description, 0, 2000);

        return $this;
    }

    public function getProgression(): int
    {
        return $this->progression;
    }

    /** Nouveau pourcentage (0 à 100), noté dans l'historique à la date donnée (une valeur par jour). */
    public function setProgression(int $progression, ?\DateTimeImmutable $le = null): static
    {
        $this->progression = max(0, min(100, $progression));
        $jour = ($le ?? new \DateTimeImmutable('today'))->format('Y-m-d');
        $historique = array_values(array_filter($this->historique, fn (array $h) => $h[0] !== $jour));
        $historique[] = [$jour, $this->progression];
        usort($historique, fn (array $a, array $b) => $a[0] <=> $b[0]);
        $this->historique = $historique;

        return $this;
    }

    /** @return list<array{0: string, 1: int}> */
    public function getHistorique(): array
    {
        return $this->historique;
    }

    /** Pourcentage atteint à une date (dernière mise à jour jusqu'à ce jour), null si l'objectif n'existait pas encore. */
    public function progressionAu(\DateTimeImmutable $date): ?int
    {
        $valeur = null;
        foreach ($this->historique as [$jour, $p]) {
            if ($jour <= $date->format('Y-m-d')) {
                $valeur = (int) $p;
            }
        }

        return $valeur;
    }

    public function isAtteint(): bool
    {
        return $this->progression >= 100;
    }

    public function getEcheance(): ?\DateTimeImmutable
    {
        return $this->echeance;
    }

    public function setEcheance(?\DateTimeImmutable $echeance): static
    {
        $this->echeance = $echeance;

        return $this;
    }

    public function getCreeLe(): \DateTimeImmutable
    {
        return $this->creeLe;
    }

    public function getCreeParNom(): ?string
    {
        return $this->creeParNom;
    }

    public function setCreeParNom(?string $nom): static
    {
        $this->creeParNom = $nom;

        return $this;
    }
}
