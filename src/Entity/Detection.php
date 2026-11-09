<?php

namespace App\Entity;

use App\Repository\DetectionRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Enfant venu à une journée de détection (table Access « Tbl detections »).
 */
#[ORM\Entity(repositoryClass: DetectionRepository::class)]
#[ORM\Index(columns: ['date_detection'])]
class Detection implements AppartientAuClub
{
    use ClubTrait;

    public const NIVEAUX = ['Promotion', 'Honneur', 'Excellence'];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $dateDetection = null;

    #[ORM\Column(length: 50)]
    #[Assert\NotBlank]
    private ?string $nom = null;

    #[ORM\Column(length: 50, nullable: true)]
    private ?string $prenom = null;

    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $dateNaissance = null;

    #[ORM\Column(length: 80, nullable: true)]
    private ?string $lieuNaissance = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $photo = null;

    #[ORM\Column(length: 120, nullable: true)]
    private ?string $adresse = null;

    #[ORM\Column(length: 10, nullable: true)]
    private ?string $codePostal = null;

    #[ORM\Column(length: 60, nullable: true)]
    private ?string $ville = null;

    #[ORM\Column(length: 20, nullable: true)]
    private ?string $telFixe = null;

    #[ORM\Column(length: 20, nullable: true)]
    private ?string $telMobile = null;

    #[ORM\Column(length: 180, nullable: true)]
    #[Assert\Email]
    private ?string $email = null;

    #[ORM\Column(length: 20, nullable: true)]
    private ?string $pied = null;

    #[ORM\Column(length: 40, nullable: true)]
    private ?string $poste = null;

    #[ORM\Column(length: 40, nullable: true)]
    private ?string $posteSecondaire = null;

    #[ORM\Column(length: 80, nullable: true)]
    private ?string $clubActuel = null;

    #[ORM\Column(length: 30, nullable: true)]
    private ?string $niveau = null;

    #[ORM\Column(length: 4, nullable: true)]
    private ?string $anneePremiereLicence = null;

    #[ORM\Column(length: 80, nullable: true)]
    private ?string $educateurClub = null;

    #[ORM\Column(length: 20, nullable: true)]
    private ?string $telEducateur = null;

    #[ORM\Column(type: Types::SMALLINT, nullable: true)]
    #[Assert\PositiveOrZero]
    private ?int $nombreSeances = null;

    #[ORM\Column(length: 5, nullable: true)]
    private ?string $numeroMaillot = null;

    #[ORM\Column(length: 20, nullable: true)]
    private ?string $couleurMaillot = null;

    /** En kilogrammes. */
    #[ORM\Column(nullable: true)]
    #[Assert\Range(min: 10, max: 120)]
    private ?float $poids = null;

    /** En centimètres. */
    #[ORM\Column(nullable: true)]
    #[Assert\Range(min: 80, max: 210)]
    private ?float $taille = null;

    #[ORM\Column(length: 80, nullable: true)]
    private ?string $etablissementScolaire = null;

    #[ORM\Column(length: 60, nullable: true)]
    private ?string $villeScolaire = null;

    #[ORM\Column(length: 20, nullable: true)]
    private ?string $classe = null;

    #[ORM\Column]
    private bool $sectionSportive = false;

    /** Case « Option » d'Access : enfant retenu pour être revu. */
    #[ORM\Column]
    private bool $retenu = false;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $memo = null;

    /** Fiche joueur créée si l'enfant a rejoint le club. */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?Joueur $joueur = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getDateDetection(): ?\DateTimeImmutable
    {
        return $this->dateDetection;
    }

    public function setDateDetection(?\DateTimeImmutable $dateDetection): static
    {
        $this->dateDetection = $dateDetection;

        return $this;
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

    public function getDateNaissance(): ?\DateTimeImmutable
    {
        return $this->dateNaissance;
    }

    public function setDateNaissance(?\DateTimeImmutable $dateNaissance): static
    {
        $this->dateNaissance = $dateNaissance;

        return $this;
    }

    public function getLieuNaissance(): ?string
    {
        return $this->lieuNaissance;
    }

    public function setLieuNaissance(?string $lieuNaissance): static
    {
        $this->lieuNaissance = $lieuNaissance;

        return $this;
    }

    public function getPhoto(): ?string
    {
        return $this->photo;
    }

    public function setPhoto(?string $photo): static
    {
        $this->photo = $photo;

        return $this;
    }

    public function getAdresse(): ?string
    {
        return $this->adresse;
    }

    public function setAdresse(?string $adresse): static
    {
        $this->adresse = $adresse;

        return $this;
    }

    public function getCodePostal(): ?string
    {
        return $this->codePostal;
    }

    public function setCodePostal(?string $codePostal): static
    {
        $this->codePostal = $codePostal;

        return $this;
    }

    public function getVille(): ?string
    {
        return $this->ville;
    }

    public function setVille(?string $ville): static
    {
        $this->ville = $ville;

        return $this;
    }

    public function getTelFixe(): ?string
    {
        return $this->telFixe;
    }

    public function setTelFixe(?string $telFixe): static
    {
        $this->telFixe = $telFixe;

        return $this;
    }

    public function getTelMobile(): ?string
    {
        return $this->telMobile;
    }

    public function setTelMobile(?string $telMobile): static
    {
        $this->telMobile = $telMobile;

        return $this;
    }

    public function getEmail(): ?string
    {
        return $this->email;
    }

    public function setEmail(?string $email): static
    {
        $this->email = $email;

        return $this;
    }

    public function getPied(): ?string
    {
        return $this->pied;
    }

    public function setPied(?string $pied): static
    {
        $this->pied = $pied;

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

    public function getPosteSecondaire(): ?string
    {
        return $this->posteSecondaire;
    }

    public function setPosteSecondaire(?string $posteSecondaire): static
    {
        $this->posteSecondaire = $posteSecondaire;

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

    public function getAnneePremiereLicence(): ?string
    {
        return $this->anneePremiereLicence;
    }

    public function setAnneePremiereLicence(?string $anneePremiereLicence): static
    {
        $this->anneePremiereLicence = $anneePremiereLicence;

        return $this;
    }

    public function getEducateurClub(): ?string
    {
        return $this->educateurClub;
    }

    public function setEducateurClub(?string $educateurClub): static
    {
        $this->educateurClub = $educateurClub;

        return $this;
    }

    public function getTelEducateur(): ?string
    {
        return $this->telEducateur;
    }

    public function setTelEducateur(?string $telEducateur): static
    {
        $this->telEducateur = $telEducateur;

        return $this;
    }

    public function getNombreSeances(): ?int
    {
        return $this->nombreSeances;
    }

    public function setNombreSeances(?int $nombreSeances): static
    {
        $this->nombreSeances = $nombreSeances;

        return $this;
    }

    public function getNumeroMaillot(): ?string
    {
        return $this->numeroMaillot;
    }

    public function setNumeroMaillot(?string $numeroMaillot): static
    {
        $this->numeroMaillot = $numeroMaillot;

        return $this;
    }

    public function getCouleurMaillot(): ?string
    {
        return $this->couleurMaillot;
    }

    public function setCouleurMaillot(?string $couleurMaillot): static
    {
        $this->couleurMaillot = $couleurMaillot;

        return $this;
    }

    public function getPoids(): ?float
    {
        return $this->poids;
    }

    public function setPoids(?float $poids): static
    {
        $this->poids = $poids;

        return $this;
    }

    public function getTaille(): ?float
    {
        return $this->taille;
    }

    public function setTaille(?float $taille): static
    {
        $this->taille = $taille;

        return $this;
    }

    public function getEtablissementScolaire(): ?string
    {
        return $this->etablissementScolaire;
    }

    public function setEtablissementScolaire(?string $etablissementScolaire): static
    {
        $this->etablissementScolaire = $etablissementScolaire;

        return $this;
    }

    public function getVilleScolaire(): ?string
    {
        return $this->villeScolaire;
    }

    public function setVilleScolaire(?string $villeScolaire): static
    {
        $this->villeScolaire = $villeScolaire;

        return $this;
    }

    public function getClasse(): ?string
    {
        return $this->classe;
    }

    public function setClasse(?string $classe): static
    {
        $this->classe = $classe;

        return $this;
    }

    public function isSectionSportive(): bool
    {
        return $this->sectionSportive;
    }

    public function setSectionSportive(bool $sectionSportive): static
    {
        $this->sectionSportive = $sectionSportive;

        return $this;
    }

    public function isRetenu(): bool
    {
        return $this->retenu;
    }

    public function setRetenu(bool $retenu): static
    {
        $this->retenu = $retenu;

        return $this;
    }

    public function getMemo(): ?string
    {
        return $this->memo;
    }

    public function setMemo(?string $memo): static
    {
        $this->memo = $memo;

        return $this;
    }

    public function getJoueur(): ?Joueur
    {
        return $this->joueur;
    }

    public function setJoueur(?Joueur $joueur): static
    {
        $this->joueur = $joueur;

        return $this;
    }

    /** Année de naissance : c'est par génération que les détections sont comparées. */
    public function getGeneration(): ?string
    {
        return $this->dateNaissance?->format('Y');
    }
}
