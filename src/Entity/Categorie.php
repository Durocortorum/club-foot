<?php

namespace App\Entity;

use App\Repository\CategorieRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
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

    /** Équipe féminine (U15 F, Seniors F…). */
    #[ORM\Column(options: ['default' => false])]
    private bool $feminine = false;

    /** Durée d'un match en minutes (deux mi-temps), null si la catégorie ne joue pas de matchs à feuille. */
    #[ORM\Column(type: 'smallint', nullable: true)]
    #[Assert\Range(min: 10, max: 120)]
    private ?int $dureeMatch = null;

    /**
     * Éducateur responsable des matchs choisi à la main par l'administrateur. Vide : le ou les éducateurs de
     * l'équipe (fiches Encadrement) qui ont un compte, voir ResponsablesMatchs.
     */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?Utilisateur $responsableMatchs = null;

    /**
     * Personnes désignées par l'éducateur responsable pour tenir les rapports de match avec lui.
     *
     * @var Collection<int, Utilisateur>
     */
    #[ORM\ManyToMany(targetEntity: Utilisateur::class)]
    #[ORM\JoinTable(name: 'categorie_redacteur_match')]
    #[ORM\JoinColumn(onDelete: 'CASCADE')]
    #[ORM\InverseJoinColumn(onDelete: 'CASCADE')]
    #[ORM\OrderBy(['prenom' => 'ASC', 'nom' => 'ASC'])]
    private Collection $redacteursMatchs;

    public function __construct()
    {
        $this->redacteursMatchs = new ArrayCollection();
    }

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

    /** Joueurs sur le terrain d'après le nom (gardien compris) : foot à 5 jusqu'en U9, à 8 en U10 – U13, à 11 ensuite. */
    public static function joueursParDefaut(?string $nom): int
    {
        if (!preg_match('/^U\s?(\d+)/i', trim((string) $nom), $m)) {
            return 11;
        }

        return match (true) {
            (int) $m[1] <= 9 => 5,
            (int) $m[1] <= 13 => 8,
            default => 11,
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

    public function isFeminine(): bool
    {
        return $this->feminine;
    }

    public function setFeminine(bool $feminine): static
    {
        $this->feminine = $feminine;

        return $this;
    }

    /** Nom qui désigne une équipe féminine : « Féminines », « U15 F », « Seniors F », « U13 Filles »… */
    public static function nomFeminin(?string $nom): bool
    {
        return (bool) preg_match('/f[ée]minin|\bfilles?\b|\bf\d*$/iu', trim((string) $nom));
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

    public function getResponsableMatchs(): ?Utilisateur
    {
        return $this->responsableMatchs;
    }

    public function setResponsableMatchs(?Utilisateur $responsableMatchs): static
    {
        $this->responsableMatchs = $responsableMatchs;
        // Le responsable n'a pas besoin d'être aussi parmi les personnes désignées.
        if ($responsableMatchs) {
            $this->redacteursMatchs->removeElement($responsableMatchs);
        }

        return $this;
    }

    /** @return Collection<int, Utilisateur> */
    public function getRedacteursMatchs(): Collection
    {
        return $this->redacteursMatchs;
    }

    public function addRedacteurMatchs(Utilisateur $u): static
    {
        if (!$this->redacteursMatchs->contains($u) && $u !== $this->responsableMatchs) {
            $this->redacteursMatchs->add($u);
        }

        return $this;
    }

    public function removeRedacteurMatchs(Utilisateur $u): static
    {
        $this->redacteursMatchs->removeElement($u);

        return $this;
    }

    public function __toString(): string
    {
        return (string) $this->nom;
    }
}
