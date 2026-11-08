<?php

namespace App\Entity;

use App\Repository\SemainePresenceRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Semaine d'entraînement d'une équipe (table Access « Tbl Presence2 »).
 * Les présences de chaque joueur y sont rattachées.
 */
#[ORM\Entity(repositoryClass: SemainePresenceRepository::class)]
#[ORM\Index(columns: ['lundi'])]
class SemainePresence implements AppartientAuClub
{
    use ClubTrait;

    /** Demi-journées de la semaine, dans l'ordre de la grille Access. */
    public const CRENEAUX = [
        'lun_m' => 'Lundi matin', 'lun_a' => 'Lundi après-midi',
        'mar_m' => 'Mardi matin', 'mar_a' => 'Mardi après-midi',
        'mer_m' => 'Mercredi matin', 'mer_a' => 'Mercredi après-midi',
        'jeu_m' => 'Jeudi matin', 'jeu_a' => 'Jeudi après-midi',
        'ven_m' => 'Vendredi matin', 'ven_a' => 'Vendredi après-midi',
        'sam_m' => 'Samedi matin',
    ];

    /**
     * Motifs d'absence repris d'Access. Toute autre valeur dans un créneau
     * est le nom de l'équipe avec laquelle le joueur s'est entraîné.
     */
    public const MOTIFS = [
        'Excusé' => 'Excusé',
        'Blessé' => 'Blessé',
        'Malade' => 'Malade',
        'Soins' => 'Soins',
        'Dis' => 'Dispensé',
        'Absent' => 'Absent',
        'Collé' => 'Collé (retenue scolaire)',
        'Discipline' => 'Discipline',
        'Divers' => 'Divers',
    ];

    /**
     * Anciens codes d'Access, plus proposés mais encore présents dans d'anciennes grilles (VA en 2013,
     * AT en 2012) : ils restent comptés comme absences, pour que les taux de présence ne changent pas.
     */
    public const MOTIFS_ANCIENS = [
        'VA' => 'VA (ancien code)',
        'AT' => 'AT (ancien code)',
    ];

    /** @return array<string, string> motifs proposés et anciens codes encore reconnus */
    public static function tousMotifs(): array
    {
        return self::MOTIFS + self::MOTIFS_ANCIENS;
    }

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    #[Assert\NotNull]
    private ?\DateTimeImmutable $lundi = null;

    #[ORM\ManyToOne]
    #[Assert\NotNull]
    private ?Categorie $categorie = null;

    /** Libellé précis de l'équipe (ex. : « U13 Honneur »). */
    #[ORM\Column(length: 30, nullable: true)]
    private ?string $equipe = null;

    /** @var Collection<int, Presence> */
    #[ORM\OneToMany(targetEntity: Presence::class, mappedBy: 'semaine', cascade: ['persist'], orphanRemoval: true)]
    private Collection $presences;

    public function __construct()
    {
        $this->presences = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getLundi(): ?\DateTimeImmutable
    {
        return $this->lundi;
    }

    public function setLundi(?\DateTimeImmutable $lundi): static
    {
        $this->lundi = $lundi;

        return $this;
    }

    public function getSamedi(): ?\DateTimeImmutable
    {
        return $this->lundi?->modify('+5 days');
    }

    public function getCategorie(): ?Categorie
    {
        return $this->categorie;
    }

    public function setCategorie(?Categorie $categorie): static
    {
        $this->categorie = $categorie;

        return $this;
    }

    public function getEquipe(): ?string
    {
        return $this->equipe;
    }

    public function setEquipe(?string $equipe): static
    {
        $this->equipe = $equipe;

        return $this;
    }

    public function getEquipeAffichee(): string
    {
        return $this->equipe ?? (string) $this->categorie;
    }

    /** @return Collection<int, Presence> */
    public function getPresences(): Collection
    {
        return $this->presences;
    }

    public function addPresence(Presence $presence): static
    {
        if (!$this->presences->contains($presence)) {
            $this->presences->add($presence);
            $presence->setSemaine($this);
        }

        return $this;
    }

    public function removePresence(Presence $presence): static
    {
        $this->presences->removeElement($presence);

        return $this;
    }

    public static function estAbsence(?string $valeur): bool
    {
        return null !== $valeur && isset(self::tousMotifs()[$valeur]);
    }
}
