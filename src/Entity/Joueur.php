<?php

namespace App\Entity;

use App\Repository\JoueurRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Fiche joueur (table Access « FicheJoueur »).
 */
#[ORM\Entity(repositoryClass: JoueurRepository::class)]
#[ORM\Index(columns: ['nom', 'prenom'])]
class Joueur implements AppartientAuClub
{
    use ClubTrait;

    public const PIEDS = ['Droitier', 'Gaucher', 'Les deux pieds'];
    public const POSTES = ['Gardien', 'Défenseur', 'Latéral droit', 'Latéral gauche', 'Milieu', 'Milieu offensif droit', 'Milieu offensif gauche', 'Attaquant'];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    // --- Identité
    #[ORM\Column(length: 50)]
    #[Assert\NotBlank]
    private ?string $nom = null;

    #[ORM\Column(length: 50)]
    #[Assert\NotBlank]
    private ?string $prenom = null;

    #[ORM\Column(length: 30, nullable: true)]
    private ?string $surnom = null;

    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $dateNaissance = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $lieuNaissance = null;

    #[ORM\Column(length: 50, nullable: true)]
    private ?string $nationalite = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $photo = null;

    // --- Club
    #[ORM\ManyToOne]
    private ?Categorie $categorie = null;

    #[ORM\ManyToOne]
    private ?Educateur $educateur = null;

    #[ORM\Column(length: 20, nullable: true)]
    private ?string $licence = null;

    #[ORM\Column(length: 40, nullable: true)]
    private ?string $poste = null;

    #[ORM\Column(length: 20, nullable: true)]
    private ?string $pied = null;

    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $dateArrivee = null;

    #[ORM\Column]
    private bool $selectionne = false;

    #[ORM\Column]
    private bool $ancien = false;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $memo = null;

    // --- Coordonnées
    #[ORM\Column(length: 120, nullable: true)]
    private ?string $adresse = null;

    #[ORM\Column(length: 10, nullable: true)]
    private ?string $codePostal = null;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $ville = null;

    #[ORM\Column(length: 20, nullable: true)]
    private ?string $telJoueur = null;

    // Parents : un portable et un e-mail pour chacun (les anciens téléphones maison sont dans le mémo).
    #[ORM\Column(length: 20, nullable: true)]
    private ?string $telPortable1 = null;

    #[ORM\Column(length: 20, nullable: true)]
    private ?string $telPortable2 = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $emailJoueur = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $emailParent1 = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $emailParent2 = null;

    // --- Famille (parent 1 et parent 2, anciennement père et mère)
    #[ORM\Column(length: 50, nullable: true)]
    private ?string $nomParent1 = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $professionParent1 = null;

    #[ORM\Column(length: 50, nullable: true)]
    private ?string $nomParent2 = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $professionParent2 = null;

    #[ORM\Column]
    private bool $droitImage = false;

    #[ORM\Column]
    private bool $transportEducateur = false;

    #[ORM\Column]
    private bool $transportParent = false;

    // --- Scolarité
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $niveauScolaire = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $ecole = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $etablissement = null;

    /** Régime : externe, demi-pensionnaire, interne au CREPS... */
    #[ORM\Column(length: 60, nullable: true)]
    private ?string $situationScolaire = null;

    // --- Médical (visible seulement par l'infirmerie et l'administration)
    #[ORM\Column(length: 50, nullable: true)]
    private ?string $nomAssure = null;

    #[ORM\Column(length: 30, nullable: true)]
    private ?string $numeroSecu = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $medecin = null;

    #[ORM\Column(length: 30, nullable: true)]
    private ?string $telMedecin = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $renseignementsMedicaux = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getNom(): ?string
    {
        return $this->nom;
    }

    /** Nom de famille, toujours en majuscules. */
    public function setNom(string $nom): static
    {
        $this->nom = mb_strtoupper(trim($nom));

        return $this;
    }

    public function getPrenom(): ?string
    {
        return $this->prenom;
    }

    public function setPrenom(string $prenom): static
    {
        $this->prenom = Prenom::formater($prenom);

        return $this;
    }

    public function getNomComplet(): string
    {
        return trim($this->prenom . ' ' . $this->nom);
    }

    public function getSurnom(): ?string
    {
        return $this->surnom;
    }

    public function setSurnom(?string $surnom): static
    {
        $this->surnom = $surnom;

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
        return $this->dateNaissance?->diff(new \DateTimeImmutable())->y;
    }

    // Sans date de naissance, on le considère mineur : les champs des parents restent visibles.
    public function isMineur(): bool
    {
        return $this->getAge() === null || $this->getAge() < 18;
    }

    /** Un renseignement sur les parents est-il saisi (nom, profession, téléphone ou e-mail) ? */
    public function hasInfosParents(): bool
    {
        return (bool) array_filter([
            $this->nomParent1, $this->professionParent1, $this->telPortable1, $this->emailParent1,
            $this->nomParent2, $this->professionParent2, $this->telPortable2, $this->emailParent2,
        ]);
    }

    /** Afficher les parents : joueur mineur, ou majeur dont des renseignements sur les parents sont saisis. */
    public function isAvecParents(): bool
    {
        return $this->isMineur() || $this->hasInfosParents();
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

    public function getNationalite(): ?string
    {
        return $this->nationalite;
    }

    public function setNationalite(?string $nationalite): static
    {
        $this->nationalite = $nationalite;

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

    public function getCategorie(): ?Categorie
    {
        return $this->categorie;
    }

    public function setCategorie(?Categorie $categorie): static
    {
        $this->categorie = $categorie;

        return $this;
    }

    public function getEducateur(): ?Educateur
    {
        return $this->educateur;
    }

    public function setEducateur(?Educateur $educateur): static
    {
        $this->educateur = $educateur;

        return $this;
    }

    public function getLicence(): ?string
    {
        return $this->licence;
    }

    public function setLicence(?string $licence): static
    {
        $this->licence = $licence;

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

    public function getPied(): ?string
    {
        return $this->pied;
    }

    public function setPied(?string $pied): static
    {
        $this->pied = $pied;

        return $this;
    }

    public function getDateArrivee(): ?\DateTimeImmutable
    {
        return $this->dateArrivee;
    }

    public function setDateArrivee(?\DateTimeImmutable $dateArrivee): static
    {
        $this->dateArrivee = $dateArrivee;

        return $this;
    }

    public function isSelectionne(): bool
    {
        return $this->selectionne;
    }

    public function setSelectionne(bool $selectionne): static
    {
        $this->selectionne = $selectionne;

        return $this;
    }

    public function isAncien(): bool
    {
        return $this->ancien;
    }

    public function setAncien(bool $ancien): static
    {
        $this->ancien = $ancien;

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

    public function getTelJoueur(): ?string
    {
        return $this->telJoueur;
    }

    public function setTelJoueur(?string $telJoueur): static
    {
        $this->telJoueur = $telJoueur;

        return $this;
    }

    public function getTelPortable1(): ?string
    {
        return $this->telPortable1;
    }

    public function setTelPortable1(?string $telPortable1): static
    {
        $this->telPortable1 = $telPortable1;

        return $this;
    }

    public function getTelPortable2(): ?string
    {
        return $this->telPortable2;
    }

    public function setTelPortable2(?string $telPortable2): static
    {
        $this->telPortable2 = $telPortable2;

        return $this;
    }

    public function getEmailJoueur(): ?string
    {
        return $this->emailJoueur;
    }

    public function setEmailJoueur(?string $emailJoueur): static
    {
        $this->emailJoueur = $emailJoueur;

        return $this;
    }

    public function getEmailParent1(): ?string
    {
        return $this->emailParent1;
    }

    public function setEmailParent1(?string $emailParent1): static
    {
        $this->emailParent1 = $emailParent1;

        return $this;
    }

    public function getEmailParent2(): ?string
    {
        return $this->emailParent2;
    }

    public function setEmailParent2(?string $emailParent2): static
    {
        $this->emailParent2 = $emailParent2;

        return $this;
    }

    public function getNomParent1(): ?string
    {
        return $this->nomParent1;
    }

    public function setNomParent1(?string $nomParent1): static
    {
        $this->nomParent1 = $nomParent1;

        return $this;
    }

    public function getProfessionParent1(): ?string
    {
        return $this->professionParent1;
    }

    public function setProfessionParent1(?string $professionParent1): static
    {
        $this->professionParent1 = $professionParent1;

        return $this;
    }

    public function getNomParent2(): ?string
    {
        return $this->nomParent2;
    }

    public function setNomParent2(?string $nomParent2): static
    {
        $this->nomParent2 = $nomParent2;

        return $this;
    }

    public function getProfessionParent2(): ?string
    {
        return $this->professionParent2;
    }

    public function setProfessionParent2(?string $professionParent2): static
    {
        $this->professionParent2 = $professionParent2;

        return $this;
    }

    public function isDroitImage(): bool
    {
        return $this->droitImage;
    }

    public function setDroitImage(bool $droitImage): static
    {
        $this->droitImage = $droitImage;

        return $this;
    }

    public function isTransportEducateur(): bool
    {
        return $this->transportEducateur;
    }

    public function setTransportEducateur(bool $transportEducateur): static
    {
        $this->transportEducateur = $transportEducateur;

        return $this;
    }

    public function isTransportParent(): bool
    {
        return $this->transportParent;
    }

    public function setTransportParent(bool $transportParent): static
    {
        $this->transportParent = $transportParent;

        return $this;
    }

    public function getNiveauScolaire(): ?string
    {
        return $this->niveauScolaire;
    }

    public function setNiveauScolaire(?string $niveauScolaire): static
    {
        $this->niveauScolaire = $niveauScolaire;

        return $this;
    }

    public function getEcole(): ?string
    {
        return $this->ecole;
    }

    public function setEcole(?string $ecole): static
    {
        $this->ecole = $ecole;

        return $this;
    }

    public function getEtablissement(): ?string
    {
        return $this->etablissement;
    }

    public function setEtablissement(?string $etablissement): static
    {
        $this->etablissement = $etablissement;

        return $this;
    }

    public function getSituationScolaire(): ?string
    {
        return $this->situationScolaire;
    }

    public function setSituationScolaire(?string $situationScolaire): static
    {
        $this->situationScolaire = $situationScolaire;

        return $this;
    }

    public function getNomAssure(): ?string
    {
        return $this->nomAssure;
    }

    public function setNomAssure(?string $nomAssure): static
    {
        $this->nomAssure = $nomAssure;

        return $this;
    }

    public function getNumeroSecu(): ?string
    {
        return $this->numeroSecu;
    }

    public function setNumeroSecu(?string $numeroSecu): static
    {
        $this->numeroSecu = $numeroSecu;

        return $this;
    }

    public function getMedecin(): ?string
    {
        return $this->medecin;
    }

    public function setMedecin(?string $medecin): static
    {
        $this->medecin = $medecin;

        return $this;
    }

    public function getTelMedecin(): ?string
    {
        return $this->telMedecin;
    }

    public function setTelMedecin(?string $telMedecin): static
    {
        $this->telMedecin = $telMedecin;

        return $this;
    }

    public function getRenseignementsMedicaux(): ?string
    {
        return $this->renseignementsMedicaux;
    }

    public function setRenseignementsMedicaux(?string $renseignementsMedicaux): static
    {
        $this->renseignementsMedicaux = $renseignementsMedicaux;

        return $this;
    }

    public function __toString(): string
    {
        return $this->getNomComplet();
    }
}
