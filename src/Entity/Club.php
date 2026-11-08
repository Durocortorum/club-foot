<?php

namespace App\Entity;

use App\Repository\ClubRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Apparence de l'application : nom, logo et couleurs du club (une seule ligne, modifiée par l'administration).
 */
#[ORM\Entity(repositoryClass: ClubRepository::class)]
class Club
{
    /** Nom de l'application, affiché avant la connexion (provisoire, à changer ici). */
    public const NOM = 'Club Foot';
    public const COULEUR = '#1d6fd8';
    public const COULEUR_TEXTE = '#ffffff';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** Nom affiché : en-tête, page de connexion, onglet du navigateur, légendes des statistiques. */
    #[ORM\Column(length: 80, options: ['default' => self::NOM])]
    #[Assert\NotBlank]
    #[Assert\Length(max: 80)]
    private string $nom = self::NOM;

    /** Couleur principale : en-tête, boutons, liens. */
    #[ORM\Column(length: 7)]
    #[Assert\Regex('/^#[0-9a-f]{6}$/i', message: 'Couleur invalide.')]
    private string $couleur = self::COULEUR;

    /** Couleur du texte posé sur la couleur principale (en-tête, boutons). */
    #[ORM\Column(length: 7)]
    #[Assert\Regex('/^#[0-9a-f]{6}$/i', message: 'Couleur invalide.')]
    private string $couleurTexte = self::COULEUR_TEXTE;

    /** Fichier du logo dans var/club, ou null pour le logo d'origine. */
    #[ORM\Column(length: 60, nullable: true)]
    private ?string $logo = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $modifieLe;

    /** Coordonnées du club, affichées en bas des e-mails envoyés aux familles et à l'encadrement. */
    #[ORM\Column(length: 255, nullable: true)]
    #[Assert\Length(max: 255)]
    private ?string $adresse = null;

    #[ORM\Column(length: 30, nullable: true)]
    #[Assert\Length(max: 30)]
    private ?string $telephone = null;

    /** Adresse de contact : aussi adresse de réponse des e-mails (les envois partent d'une adresse technique). */
    #[ORM\Column(length: 180, nullable: true)]
    #[Assert\Email(message: 'Adresse e-mail invalide.')]
    #[Assert\Length(max: 180)]
    private ?string $emailContact = null;

    #[ORM\Column(length: 255, nullable: true)]
    #[Assert\Url(message: 'Adresse de site invalide (commencez par https://).', requireTld: true)]
    #[Assert\Length(max: 255)]
    private ?string $siteWeb = null;

    public function getAdresse(): ?string
    {
        return $this->adresse;
    }

    public function setAdresse(?string $adresse): static
    {
        $this->adresse = $adresse ? trim($adresse) : null;

        return $this;
    }

    public function getTelephone(): ?string
    {
        return $this->telephone;
    }

    public function setTelephone(?string $telephone): static
    {
        $this->telephone = $telephone ? trim($telephone) : null;

        return $this;
    }

    public function getEmailContact(): ?string
    {
        return $this->emailContact;
    }

    public function setEmailContact(?string $email): static
    {
        $this->emailContact = $email ? mb_strtolower(trim($email)) : null;

        return $this;
    }

    public function getSiteWeb(): ?string
    {
        return $this->siteWeb;
    }

    public function setSiteWeb(?string $site): static
    {
        $this->siteWeb = $site ? trim($site) : null;

        return $this;
    }

    public function __construct()
    {
        $this->modifieLe = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getNom(): string
    {
        return $this->nom;
    }

    public function setNom(?string $nom): static
    {
        $this->nom = trim((string) $nom);

        return $this;
    }

    public function getCouleur(): string
    {
        return $this->couleur;
    }

    public function setCouleur(?string $couleur): static
    {
        $this->couleur = strtolower((string) $couleur);

        return $this;
    }

    public function getCouleurTexte(): string
    {
        return $this->couleurTexte;
    }

    public function setCouleurTexte(?string $couleurTexte): static
    {
        $this->couleurTexte = strtolower((string) $couleurTexte);

        return $this;
    }

    public function getLogo(): ?string
    {
        return $this->logo;
    }

    public function setLogo(?string $logo): static
    {
        $this->logo = $logo;

        return $this;
    }

    public function getModifieLe(): \DateTimeImmutable
    {
        return $this->modifieLe;
    }

    public function marquerModifie(): static
    {
        $this->modifieLe = new \DateTimeImmutable();

        return $this;
    }

    /** Version de l'apparence, pour que les navigateurs rechargent le logo après un changement. */
    public function getVersion(): string
    {
        return $this->modifieLe->format('YmdHis');
    }

    /** Couleur principale assombrie (survol des boutons, textes sur fond clair). */
    public function getCouleurFoncee(): string
    {
        [$r, $v, $b] = sscanf($this->couleur, '#%02x%02x%02x');

        return \sprintf('#%02x%02x%02x', $r * .78, $v * .78, $b * .78);
    }
}
