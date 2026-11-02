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
