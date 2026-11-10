<?php

namespace App\Entity;

use App\Repository\CandidatureRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Candidature (CV) d'une personne qui veut rejoindre l'encadrement : éducateur, dirigeant,
 * administratif, bénévole ou membre du corps médical. Une fois retenue, elle devient une fiche
 * de l'encadrement (ou un contact médical de l'infirmerie), désignée par « type:id ».
 */
#[ORM\Entity(repositoryClass: CandidatureRepository::class)]
class Candidature implements AppartientAuClub
{
    use ClubTrait;

    /** Clé => libellé ; les clés sont celles des adresses /encadrement/{type}, plus « medical ». */
    public const POSTES = [
        'educateurs' => 'Éducateur',
        'dirigeants' => 'Dirigeant',
        'administratifs' => 'Administratif',
        'benevoles' => 'Bénévole',
        'medical' => 'Médical',
    ];

    public const STATUTS = [
        'a_etudier' => 'À étudier',
        'entretien' => 'Entretien prévu',
        'retenu' => 'Retenu',
        'refuse' => 'Refusé',
    ];

    /** Suggestions de fonction par poste visé. */
    public const FONCTIONS = [
        'educateurs' => ['Éducateur U7', 'Éducateur U9', 'Éducateur U11', 'Éducateur U13', 'Éducateur U15', 'Éducateur U17', 'Éducateur U19', 'Entraîneur seniors', 'Entraîneur des gardiens', 'Préparateur physique', 'Analyste vidéo'],
        'dirigeants' => ['Dirigeant d\'équipe', 'Responsable de catégorie', 'Membre du bureau', 'Trésorier', 'Secrétaire'],
        'administratifs' => ['Secrétariat', 'Comptabilité', 'Communication', 'Partenariats'],
        'benevoles' => ['Buvette', 'Accueil', 'Transport', 'Entretien du matériel', 'Événements', 'Arbitre bénévole'],
        'medical' => ['Médecin', 'Kinésithérapeute', 'Ostéopathe', 'Infirmier', 'Podologue', 'Préparateur mental', 'Diététicien'],
    ];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 50)]
    #[Assert\NotBlank]
    private ?string $nom = null;

    #[ORM\Column(length: 50)]
    #[Assert\NotBlank]
    private ?string $prenom = null;

    #[ORM\Column(length: 20)]
    #[Assert\Choice(choices: ['educateurs', 'dirigeants', 'administratifs', 'benevoles', 'medical'])]
    private string $posteVise = 'educateurs';

    /** Fonction précise : « Éducateur U13 », « Kinésithérapeute »… */
    #[ORM\Column(length: 100, nullable: true)]
    private ?string $fonction = null;

    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $dateNaissance = null;

    #[ORM\Column(length: 20, nullable: true)]
    private ?string $telephone = null;

    #[ORM\Column(length: 180, nullable: true)]
    #[Assert\Email]
    private ?string $email = null;

    #[ORM\Column(length: 120, nullable: true)]
    private ?string $adresse = null;

    #[ORM\Column(length: 10, nullable: true)]
    private ?string $codePostal = null;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $ville = null;

    /** Diplômes : BMF, BEF, CFF, diplôme d'État de kiné… */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $diplomes = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $experience = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $disponibilites = null;

    #[ORM\Column(length: 80, nullable: true)]
    private ?string $clubActuel = null;

    /** Comment la candidature est arrivée : recommandation, réseaux sociaux, candidature spontanée… */
    #[ORM\Column(length: 100, nullable: true)]
    private ?string $origine = null;

    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $dateReception = null;

    #[ORM\Column(length: 20)]
    #[Assert\Choice(choices: ['a_etudier', 'entretien', 'retenu', 'refuse'])]
    private string $statut = 'a_etudier';

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $dateEntretien = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $appreciation = null;

    /** CV en PDF, rangé dans var/documents. */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $cv = null;

    /** Fiche créée quand la personne a rejoint le club : « educateurs:12 », « medical:3 »… */
    #[ORM\Column(length: 30, nullable: true)]
    private ?string $fiche = null;

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
        $this->prenom = Prenom::formater($prenom);

        return $this;
    }

    public function getNomComplet(): string
    {
        return trim($this->prenom . ' ' . $this->nom);
    }

    public function getPosteVise(): string
    {
        return $this->posteVise;
    }

    public function setPosteVise(?string $posteVise): static
    {
        $this->posteVise = (string) $posteVise;

        return $this;
    }

    public function getPosteViseLibelle(): string
    {
        return self::POSTES[$this->posteVise] ?? $this->posteVise;
    }

    public function getFonction(): ?string
    {
        return $this->fonction;
    }

    public function setFonction(?string $fonction): static
    {
        $this->fonction = $fonction;

        return $this;
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

    public function getAge(): ?int
    {
        return $this->dateNaissance?->diff(new \DateTimeImmutable('today'))->y;
    }

    public function getTelephone(): ?string
    {
        return $this->telephone;
    }

    public function setTelephone(?string $telephone): static
    {
        $this->telephone = $telephone;

        return $this;
    }

    public function getEmail(): ?string
    {
        return $this->email;
    }

    public function setEmail(?string $email): static
    {
        $this->email = null === $email ? null : trim($email);

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

    public function getDiplomes(): ?string
    {
        return $this->diplomes;
    }

    public function setDiplomes(?string $diplomes): static
    {
        $this->diplomes = $diplomes;

        return $this;
    }

    public function getExperience(): ?string
    {
        return $this->experience;
    }

    public function setExperience(?string $experience): static
    {
        $this->experience = $experience;

        return $this;
    }

    public function getDisponibilites(): ?string
    {
        return $this->disponibilites;
    }

    public function setDisponibilites(?string $disponibilites): static
    {
        $this->disponibilites = $disponibilites;

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

    public function getOrigine(): ?string
    {
        return $this->origine;
    }

    public function setOrigine(?string $origine): static
    {
        $this->origine = $origine;

        return $this;
    }

    public function getDateReception(): ?\DateTimeImmutable
    {
        return $this->dateReception;
    }

    public function setDateReception(?\DateTimeImmutable $dateReception): static
    {
        $this->dateReception = $dateReception;

        return $this;
    }

    public function getStatut(): string
    {
        return $this->statut;
    }

    public function setStatut(?string $statut): static
    {
        $this->statut = (string) $statut;

        return $this;
    }

    public function getStatutLibelle(): string
    {
        return self::STATUTS[$this->statut] ?? $this->statut;
    }

    public function getDateEntretien(): ?\DateTimeImmutable
    {
        return $this->dateEntretien;
    }

    public function setDateEntretien(?\DateTimeImmutable $dateEntretien): static
    {
        $this->dateEntretien = $dateEntretien;

        return $this;
    }

    public function getAppreciation(): ?string
    {
        return $this->appreciation;
    }

    public function setAppreciation(?string $appreciation): static
    {
        $this->appreciation = $appreciation;

        return $this;
    }

    public function getCv(): ?string
    {
        return $this->cv;
    }

    public function setCv(?string $cv): static
    {
        $this->cv = $cv;

        return $this;
    }

    public function getFiche(): ?string
    {
        return $this->fiche;
    }

    public function setFiche(?string $fiche): static
    {
        $this->fiche = $fiche;

        return $this;
    }
}
