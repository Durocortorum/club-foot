<?php

namespace App\Entity;

use App\Repository\CategorieRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Catégorie d'âge ou équipe (U9, U15, Seniors...) d'un club, rattachée à un de ses secteurs.
 */
#[ORM\Entity(repositoryClass: CategorieRepository::class)]
#[ORM\UniqueConstraint(columns: ['club_id', 'nom'])]
#[UniqueEntity(['club', 'nom'], message: 'Cette catégorie existe déjà.')]
class Categorie implements AppartientAuClub
{
    use ClubTrait;

    public const SECTEUR_ECOLE_FOOT = 'ecole_foot';
    public const SECTEUR_PREFORMATION = 'preformation';
    public const SECTEUR_FORMATION = 'formation';
    public const SECTEUR_ELITE = 'elite';
    public const SECTEUR_PRO = 'pro';

    /** Libellé et rôle d'accès de chaque secteur, dans l'ordre d'affichage. */
    public const SECTEURS = [
        self::SECTEUR_ECOLE_FOOT => ['libelle' => 'École de foot', 'role' => 'ROLE_ECOLE_FOOT'],
        self::SECTEUR_PREFORMATION => ['libelle' => 'Préformation', 'role' => 'ROLE_PREFORMATION'],
        self::SECTEUR_FORMATION => ['libelle' => 'Formation', 'role' => 'ROLE_FORMATION'],
        self::SECTEUR_ELITE => ['libelle' => 'Élite', 'role' => 'ROLE_ELITE'],
        self::SECTEUR_PRO => ['libelle' => 'Pro', 'role' => 'ROLE_PRO'],
    ];

    /** Catégories proposées à la création d'un club (modifiables ensuite), par secteur. */
    public const MODELES = [
        self::SECTEUR_ECOLE_FOOT => ['U7', 'U9', 'U11'],
        self::SECTEUR_PREFORMATION => ['U13', 'U15'],
        self::SECTEUR_FORMATION => ['U17', 'U19'],
        self::SECTEUR_ELITE => ['Seniors', 'Vétérans'],
    ];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 30)]
    private ?string $nom = null;

    #[ORM\Column(length: 20)]
    private ?string $secteur = null;

    #[ORM\Column]
    private int $ordre = 0;

    /** Durée d'un match en minutes (deux mi-temps), null si la catégorie ne joue pas de matchs à feuille. */
    #[ORM\Column(type: 'smallint', nullable: true)]
    #[Assert\Range(min: 10, max: 120)]
    private ?int $dureeMatch = null;

    /** Durée de match proposée d'après le nom (U11 : 2 × 25, U13 : 2 × 30, U15 : 2 × 40, au-delà : 2 × 45). */
    public static function dureeParDefaut(?string $nom): ?int
    {
        if (!preg_match('/^U\s?(\d+)/i', trim((string) $nom), $m)) {
            return 90;
        }

        return match (true) {
            (int) $m[1] <= 9 => null,
            (int) $m[1] <= 11 => 50,
            (int) $m[1] <= 13 => 60,
            (int) $m[1] <= 15 => 80,
            default => 90,
        };
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getNom(): ?string
    {
        return $this->nom;
    }

    /** Nom de l'équipe, toujours en majuscules. */
    public function setNom(string $nom): static
    {
        $this->nom = mb_strtoupper(trim($nom));

        return $this;
    }

    public function getSecteur(): ?string
    {
        return $this->secteur;
    }

    public function setSecteur(string $secteur): static
    {
        $this->secteur = $secteur;

        return $this;
    }

    public function getSecteurLibelle(): string
    {
        return self::SECTEURS[$this->secteur]['libelle'] ?? (string) $this->secteur;
    }

    public function getOrdre(): int
    {
        return $this->ordre;
    }

    public function setOrdre(int $ordre): static
    {
        $this->ordre = $ordre;

        return $this;
    }

    /** Âge de la catégorie d'après son nom (« U15 PH » → 15), 99 pour les seniors et équipes sans âge. */
    public function getAge(): int
    {
        return preg_match('/^U\s?(\d+)/i', trim((string) $this->nom), $m) ? (int) $m[1] : 99;
    }

    /**
     * Les joueurs de $autre peuvent-ils jouer dans cette catégorie (surclassement) ? Oui pour les deux âges
     * juste en dessous et les autres équipes du même âge (U17 : U15, U16, U17 DH) ; seniors : à partir de U17.
     */
    public function accueilleEnSurclassement(self $autre): bool
    {
        if ($autre === $this || $autre->getClub() !== $this->getClub()) {
            return false;
        }
        $age = $this->getAge();

        return 99 === $age ? $autre->getAge() >= 17 : $autre->getAge() >= $age - 2 && $autre->getAge() <= $age;
    }

    public function getDureeMatch(): ?int
    {
        return $this->dureeMatch;
    }

    public function setDureeMatch(?int $dureeMatch): static
    {
        $this->dureeMatch = $dureeMatch;

        return $this;
    }

    public function __toString(): string
    {
        return (string) $this->nom;
    }
}
