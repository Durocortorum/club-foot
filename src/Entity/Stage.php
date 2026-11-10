<?php

namespace App\Entity;

use App\Repository\StageRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * Stage organisé par le club : dates, lieu, équipes concernées, raison, tarif éventuel,
 * et joueurs inscrits (par l'encadrement ou, si c'est ouvert, par leur famille).
 */
#[ORM\Entity(repositoryClass: StageRepository::class)]
class Stage implements AppartientAuClub
{
    use ClubTrait;

    public const RAISONS = [
        'Perfectionnement', 'Préparation de saison', 'Stage vacances', 'Gardiens de but', 'Détection',
        'Cohésion d\'équipe', 'Remise en forme', 'Formation des éducateurs',
    ];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 120)]
    #[Assert\NotBlank(message: 'Donnez un titre au stage.')]
    private ?string $titre = null;

    #[ORM\Column(length: 80, nullable: true)]
    private ?string $raison = null;

    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    #[Assert\NotNull(message: 'Indiquez le premier jour du stage.')]
    private ?\DateTimeImmutable $debut = null;

    /** Dernier jour ; vide = stage d'une journée. */
    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $fin = null;

    /** « 9 h – 17 h », « matin seulement »… */
    #[ORM\Column(length: 80, nullable: true)]
    private ?string $horaires = null;

    #[ORM\Column(length: 150, nullable: true)]
    private ?string $lieu = null;

    /** Équipes concernées ; aucune = tout le club. */
    #[ORM\ManyToMany(targetEntity: Categorie::class)]
    #[ORM\OrderBy(['ordre' => 'ASC'])]
    private Collection $categories;

    /** Prix par joueur ; vide = gratuit. */
    #[ORM\Column(type: Types::DECIMAL, precision: 8, scale: 2, nullable: true)]
    #[Assert\Regex(pattern: '/^\d{1,6}(\.\d{1,2})?$/', message: 'Indiquez un montant, par exemple 45 ou 45,50.')]
    private ?string $tarif = null;

    /** « repas compris », « 60 € pour le 2e enfant »… */
    #[ORM\Column(length: 150, nullable: true)]
    private ?string $detailTarif = null;

    #[ORM\Column(nullable: true)]
    #[Assert\Positive(message: 'Le nombre de places doit être positif.')]
    private ?int $places = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $description = null;

    /** Les familles inscrivent elles-mêmes leur enfant depuis leur espace. */
    #[ORM\Column]
    private bool $inscriptionsFamilles = false;

    /** Dernier jour pour s'inscrire depuis l'espace famille ; vide = jusqu'à la veille du stage. */
    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $dateLimite = null;

    /** @var Collection<int, InscriptionStage> */
    #[ORM\OneToMany(mappedBy: 'stage', targetEntity: InscriptionStage::class, cascade: ['remove'], orphanRemoval: true)]
    private Collection $inscriptions;

    public function __construct()
    {
        $this->categories = new ArrayCollection();
        $this->inscriptions = new ArrayCollection();
    }

    public function __toString(): string
    {
        return (string) $this->titre;
    }

    #[Assert\Callback]
    public function verifierDates(ExecutionContextInterface $context): void
    {
        if ($this->debut && $this->fin && $this->fin < $this->debut) {
            $context->buildViolation('Le dernier jour doit être après le premier.')->atPath('fin')->addViolation();
        }
        if ($this->debut && $this->dateLimite && $this->dateLimite > $this->getDernierJour()) {
            $context->buildViolation('La date limite d\'inscription doit être avant la fin du stage.')->atPath('dateLimite')->addViolation();
        }
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTitre(): ?string
    {
        return $this->titre;
    }

    public function setTitre(?string $titre): static
    {
        $this->titre = null === $titre ? null : trim($titre);

        return $this;
    }

    public function getRaison(): ?string
    {
        return $this->raison;
    }

    public function setRaison(?string $raison): static
    {
        $this->raison = $raison;

        return $this;
    }

    public function getDebut(): ?\DateTimeImmutable
    {
        return $this->debut;
    }

    public function setDebut(?\DateTimeImmutable $debut): static
    {
        $this->debut = $debut;

        return $this;
    }

    public function getFin(): ?\DateTimeImmutable
    {
        return $this->fin;
    }

    public function setFin(?\DateTimeImmutable $fin): static
    {
        $this->fin = $fin;

        return $this;
    }

    public function getDernierJour(): \DateTimeImmutable
    {
        return $this->fin ?? $this->debut;
    }

    /** Nombre de jours du stage. */
    public function getDuree(): int
    {
        return $this->debut ? (int) $this->debut->diff($this->getDernierJour())->days + 1 : 0;
    }

    public function getHoraires(): ?string
    {
        return $this->horaires;
    }

    public function setHoraires(?string $horaires): static
    {
        $this->horaires = $horaires;

        return $this;
    }

    public function getLieu(): ?string
    {
        return $this->lieu;
    }

    public function setLieu(?string $lieu): static
    {
        $this->lieu = $lieu;

        return $this;
    }

    /** @return Collection<int, Categorie> */
    public function getCategories(): Collection
    {
        return $this->categories;
    }

    public function addCategory(Categorie $categorie): static
    {
        if (!$this->categories->contains($categorie)) {
            $this->categories->add($categorie);
        }

        return $this;
    }

    public function removeCategory(Categorie $categorie): static
    {
        $this->categories->removeElement($categorie);

        return $this;
    }

    public function isToutLeClub(): bool
    {
        return $this->categories->isEmpty();
    }

    /** Le stage concerne-t-il l'une de ces catégories (ou tout le club) ? */
    public function concerne(array $categories): bool
    {
        if ($this->isToutLeClub()) {
            return true;
        }
        foreach ($categories as $c) {
            if ($this->categories->contains($c)) {
                return true;
            }
        }

        return false;
    }

    public function getTarif(): ?string
    {
        return $this->tarif;
    }

    public function setTarif(?string $tarif): static
    {
        $this->tarif = null === $tarif || '' === trim($tarif) ? null : str_replace(',', '.', trim($tarif));

        return $this;
    }

    public function isPayant(): bool
    {
        return null !== $this->tarif && (float) $this->tarif > 0;
    }

    public function getDetailTarif(): ?string
    {
        return $this->detailTarif;
    }

    public function setDetailTarif(?string $detailTarif): static
    {
        $this->detailTarif = $detailTarif;

        return $this;
    }

    public function getPlaces(): ?int
    {
        return $this->places;
    }

    public function setPlaces(?int $places): static
    {
        $this->places = $places;

        return $this;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): static
    {
        $this->description = $description;

        return $this;
    }

    public function isInscriptionsFamilles(): bool
    {
        return $this->inscriptionsFamilles;
    }

    public function setInscriptionsFamilles(bool $inscriptionsFamilles): static
    {
        $this->inscriptionsFamilles = $inscriptionsFamilles;

        return $this;
    }

    public function getDateLimite(): ?\DateTimeImmutable
    {
        return $this->dateLimite;
    }

    public function setDateLimite(?\DateTimeImmutable $dateLimite): static
    {
        $this->dateLimite = $dateLimite;

        return $this;
    }

    /** @return Collection<int, InscriptionStage> */
    public function getInscriptions(): Collection
    {
        return $this->inscriptions;
    }

    public function inscription(Joueur $joueur): ?InscriptionStage
    {
        foreach ($this->inscriptions as $i) {
            if ($i->getJoueur() === $joueur) {
                return $i;
            }
        }

        return null;
    }

    public function inscrire(Joueur $joueur, bool $parFamille = false): InscriptionStage
    {
        if ($existante = $this->inscription($joueur)) {
            return $existante;
        }
        $inscription = new InscriptionStage($this, $joueur, $parFamille);
        $this->inscriptions->add($inscription);

        return $inscription;
    }

    public function desinscrire(Joueur $joueur): void
    {
        if ($i = $this->inscription($joueur)) {
            $this->inscriptions->removeElement($i);
        }
    }

    public function isComplet(): bool
    {
        return null !== $this->places && $this->inscriptions->count() >= $this->places;
    }

    public function getPlacesRestantes(): ?int
    {
        return null === $this->places ? null : max(0, $this->places - $this->inscriptions->count());
    }

    /** Montant encaissé (inscriptions marquées payées). */
    public function getEncaisse(): float
    {
        $payes = $this->inscriptions->filter(fn (InscriptionStage $i) => $i->isPaye())->count();

        return $payes * (float) $this->tarif;
    }

    /** Les familles peuvent-elles encore s'inscrire (ou se désinscrire) depuis leur espace ? */
    public function isInscriptionOuverte(?\DateTimeImmutable $aujourdhui = null): bool
    {
        $aujourdhui ??= new \DateTimeImmutable('today');
        $limite = $this->dateLimite ?? $this->debut?->modify('-1 day');

        return $this->inscriptionsFamilles && $limite && $aujourdhui <= $limite;
    }
}
