<?php

namespace App\Entity;

use App\Repository\TournoiRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Tournoi d'une équipe : lieu, dates, organisateur, transport, coût d'inscription, résultat final.
 * Ses matchs sont des rencontres « Tournoi » reliées à lui (score et convocation comme un match).
 */
#[ORM\Entity(repositoryClass: TournoiRepository::class)]
#[Assert\Expression('!this.getDebut() or !this.getFin() or this.getFin() >= this.getDebut()', message: 'La fin ne peut pas précéder le début.')]
class Tournoi implements AppartientAuClub
{
    use ClubTrait;

    public const TRANSPORTS = ['Car / bus', 'Minibus du club', 'Voitures des parents', 'Voitures de l\'encadrement', 'Train', 'Sur place (à domicile)'];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 150)]
    #[Assert\NotBlank(message: 'Indiquez le nom du tournoi.')]
    private ?string $nom = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    #[Assert\NotNull(message: 'Choisissez l\'équipe.')]
    private ?Categorie $categorie = null;

    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    #[Assert\NotNull(message: 'Indiquez la date.')]
    private ?\DateTimeImmutable $debut = null;

    /** Dernier jour, s'il dure plusieurs jours. */
    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $fin = null;

    #[ORM\Column(length: 150, nullable: true)]
    private ?string $lieu = null;

    #[ORM\Column(length: 150, nullable: true)]
    private ?string $organisateur = null;

    /** Ex. : « 4 poules de 4, puis matchs de classement ; 2 × 10 min ». */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $format = null;

    #[ORM\Column(length: 40, nullable: true)]
    private ?string $transport = null;

    /** Départ, horaires, conducteurs… */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $detailTransport = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 8, scale: 2, nullable: true)]
    #[Assert\PositiveOrZero(message: 'Le coût ne peut pas être négatif.')]
    private ?string $coutInscription = null;

    /** Ex. : « Vainqueur », « 3e sur 16 », « Quart de finale ». */
    #[ORM\Column(length: 80, nullable: true)]
    private ?string $resultat = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $description = null;

    /** @var Collection<int, Rencontre> */
    #[ORM\OneToMany(targetEntity: Rencontre::class, mappedBy: 'tournoi')]
    #[ORM\OrderBy(['date' => 'ASC', 'heure' => 'ASC', 'id' => 'ASC'])]
    private Collection $matchs;

    public function __construct()
    {
        $this->matchs = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getNom(): ?string
    {
        return $this->nom;
    }

    public function setNom(?string $nom): static
    {
        $this->nom = $nom;

        return $this;
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

    public function getDernierJour(): ?\DateTimeImmutable
    {
        return $this->fin ?? $this->debut;
    }

    public function getLieu(): ?string
    {
        return $this->lieu;
    }

    public function setLieu(?string $lieu): static
    {
        $this->lieu = $lieu ?: null;

        return $this;
    }

    public function getOrganisateur(): ?string
    {
        return $this->organisateur;
    }

    public function setOrganisateur(?string $organisateur): static
    {
        $this->organisateur = $organisateur ?: null;

        return $this;
    }

    public function getFormat(): ?string
    {
        return $this->format;
    }

    public function setFormat(?string $format): static
    {
        $this->format = $format ?: null;

        return $this;
    }

    public function getTransport(): ?string
    {
        return $this->transport;
    }

    public function setTransport(?string $transport): static
    {
        $this->transport = $transport ? trim($transport) : null;

        return $this;
    }

    public function getDetailTransport(): ?string
    {
        return $this->detailTransport;
    }

    public function setDetailTransport(?string $detailTransport): static
    {
        $this->detailTransport = $detailTransport ?: null;

        return $this;
    }

    public function getCoutInscription(): ?float
    {
        return null === $this->coutInscription ? null : (float) $this->coutInscription;
    }

    public function setCoutInscription(?float $cout): static
    {
        $this->coutInscription = null === $cout ? null : number_format($cout, 2, '.', '');

        return $this;
    }

    public function getResultat(): ?string
    {
        return $this->resultat;
    }

    public function setResultat(?string $resultat): static
    {
        $this->resultat = $resultat ?: null;

        return $this;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): static
    {
        $this->description = $description ?: null;

        return $this;
    }

    /** @return Collection<int, Rencontre> */
    public function getMatchs(): Collection
    {
        return $this->matchs;
    }

    /** Bilan des matchs joués : victoires, nuls, défaites, buts pour et contre. */
    public function getBilan(): array
    {
        $bilan = ['V' => 0, 'N' => 0, 'D' => 0, 'pour' => 0, 'contre' => 0, 'joues' => 0];
        foreach ($this->matchs as $m) {
            if (null === ($r = $m->getResultat())) {
                continue;
            }
            ++$bilan[$r];
            ++$bilan['joues'];
            $bilan['pour'] += (int) $m->getButsPour();
            $bilan['contre'] += (int) $m->getButsContre();
        }

        return $bilan;
    }

    public function __toString(): string
    {
        return (string) $this->nom;
    }
}
