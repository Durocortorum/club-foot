<?php

namespace App\Entity;

use App\Repository\EntrainementRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Entraînement daté d'une équipe, tiré de son planning (CreneauEntrainement).
 * Toute l'équipe est attendue ; les familles signalent les absences (AbsenceEntrainement).
 */
#[ORM\Entity(repositoryClass: EntrainementRepository::class)]
#[ORM\UniqueConstraint(columns: ['creneau_id', 'date'])]
#[ORM\Index(columns: ['date'])]
class Entrainement implements AppartientAuClub
{
    use ClubTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Categorie $categorie = null;

    /** Créneau d'origine ; null s'il a été supprimé depuis (entraînements passés conservés). */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?CreneauEntrainement $creneau = null;

    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    private ?\DateTimeImmutable $date = null;

    #[ORM\Column(length: 5)]
    private string $heureDebut = '14:00';

    #[ORM\Column(length: 5, nullable: true)]
    private ?string $heureFin = null;

    #[ORM\Column(length: 120, nullable: true)]
    private ?string $lieu = null;

    #[ORM\Column(options: ['default' => false])]
    private bool $annule = false;

    #[ORM\Column(length: 120, nullable: true)]
    private ?string $motifAnnulation = null;

    /** @var Collection<int, AbsenceEntrainement> */
    #[ORM\OneToMany(targetEntity: AbsenceEntrainement::class, mappedBy: 'entrainement', cascade: ['persist'], orphanRemoval: true)]
    private Collection $absences;

    public function __construct()
    {
        $this->absences = new ArrayCollection();
    }

    public static function depuis(CreneauEntrainement $creneau, \DateTimeImmutable $date): self
    {
        return (new self())->setCreneau($creneau)->setCategorie($creneau->getCategorie())->setDate($date)->appliquer($creneau);
    }

    /** Reprend l'horaire et le lieu du créneau. */
    public function appliquer(CreneauEntrainement $creneau): static
    {
        $this->heureDebut = $creneau->getHeureDebut();
        $this->heureFin = $creneau->getHeureFin();
        $this->lieu = $creneau->getLieu();

        return $this;
    }

    public function getId(): ?int
    {
        return $this->id;
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

    public function getCreneau(): ?CreneauEntrainement
    {
        return $this->creneau;
    }

    public function setCreneau(?CreneauEntrainement $creneau): static
    {
        $this->creneau = $creneau;

        return $this;
    }

    public function getDate(): ?\DateTimeImmutable
    {
        return $this->date;
    }

    public function setDate(\DateTimeImmutable $date): static
    {
        $this->date = $date;

        return $this;
    }

    public function getHeureDebut(): string
    {
        return $this->heureDebut;
    }

    public function getHeureFin(): ?string
    {
        return $this->heureFin;
    }

    public function getHoraire(): string
    {
        return str_replace(':', 'h', $this->heureDebut) . ($this->heureFin ? ' – ' . str_replace(':', 'h', $this->heureFin) : '');
    }

    public function getLieu(): ?string
    {
        return $this->lieu;
    }

    public function isAnnule(): bool
    {
        return $this->annule;
    }

    public function getMotifAnnulation(): ?string
    {
        return $this->motifAnnulation;
    }

    public function annuler(?string $motif): static
    {
        $this->annule = true;
        $this->motifAnnulation = mb_substr(trim((string) $motif), 0, 120) ?: null;

        return $this;
    }

    public function retablir(): static
    {
        $this->annule = false;
        $this->motifAnnulation = null;

        return $this;
    }

    /** Les familles peuvent signaler une absence jusqu'au jour même, sauf entraînement annulé. */
    public function isOuvert(): bool
    {
        return !$this->annule && $this->date >= new \DateTimeImmutable('today');
    }

    /** @return list<AbsenceEntrainement> triées par nom */
    public function getAbsences(): array
    {
        $liste = $this->absences->toArray();
        usort($liste, fn (AbsenceEntrainement $a, AbsenceEntrainement $b) => [$a->getJoueur()->getNom(), $a->getJoueur()->getPrenom()] <=> [$b->getJoueur()->getNom(), $b->getJoueur()->getPrenom()]);

        return $liste;
    }

    public function absenceDe(Joueur $joueur): ?AbsenceEntrainement
    {
        foreach ($this->absences as $a) {
            if ($a->getJoueur() === $joueur) {
                return $a;
            }
        }

        return null;
    }

    public function ajouterAbsence(AbsenceEntrainement $absence): static
    {
        $this->absences->add($absence);

        return $this;
    }

    public function retirerAbsence(AbsenceEntrainement $absence): static
    {
        $this->absences->removeElement($absence);

        return $this;
    }

    /** Créneau de la grille des présences (SemainePresence::CRENEAUX) : « mer_a »..., null le dimanche. */
    public function getCreneauGrille(): ?string
    {
        $jour = [1 => 'lun', 2 => 'mar', 3 => 'mer', 4 => 'jeu', 5 => 'ven', 6 => 'sam'][(int) $this->date->format('N')] ?? null;
        $cle = $jour ? $jour . '_' . ((int) substr($this->heureDebut, 0, 2) < 12 ? 'm' : 'a') : null;

        return isset(SemainePresence::CRENEAUX[$cle]) ? $cle : null;
    }
}
