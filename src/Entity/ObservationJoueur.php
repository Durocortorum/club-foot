<?php

namespace App\Entity;

use App\Repository\ObservationJoueurRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Observation de l'entraîneur sur un joueur, pour le guider et l'encourager : commentaire daté, objectif concerné,
 * vidéo éventuelle (exercice, match, action à améliorer ou réussie), montrée ou non au joueur et à sa famille.
 */
#[ORM\Entity(repositoryClass: ObservationJoueurRepository::class)]
class ObservationJoueur implements AppartientAuClub
{
    use ClubTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Joueur $joueur;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?ObjectifJoueur $objectif = null;

    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    private \DateTimeImmutable $date;

    #[ORM\Column(type: Types::TEXT)]
    private string $texte = '';

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $lienVideo = null;

    /** Visible par le joueur et sa famille (espace famille). */
    #[ORM\Column(options: ['default' => true])]
    private bool $partage = true;

    #[ORM\Column(length: 170, nullable: true)]
    private ?string $auteurNom = null;

    public function __construct(Joueur $joueur)
    {
        $this->joueur = $joueur;
        $this->date = new \DateTimeImmutable('today');
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getJoueur(): Joueur
    {
        return $this->joueur;
    }

    public function getObjectif(): ?ObjectifJoueur
    {
        return $this->objectif;
    }

    public function setObjectif(?ObjectifJoueur $objectif): static
    {
        $this->objectif = $objectif;

        return $this;
    }

    public function getDate(): \DateTimeImmutable
    {
        return $this->date;
    }

    public function setDate(\DateTimeImmutable $date): static
    {
        $this->date = $date;

        return $this;
    }

    public function getTexte(): string
    {
        return $this->texte;
    }

    public function setTexte(?string $texte): static
    {
        $this->texte = mb_substr(trim((string) $texte), 0, 3000);

        return $this;
    }

    public function getLienVideo(): ?string
    {
        return $this->lienVideo;
    }

    /** Lien http(s) seulement ; « www.… » complété en https. Renvoie false si le lien n'est pas valable. */
    public function setLienVideo(?string $lien): bool
    {
        $lien = trim((string) $lien);
        if ('' === $lien) {
            $this->lienVideo = null;

            return true;
        }
        if (!preg_match('#^https?://#i', $lien)) {
            $lien = 'https://' . $lien;
        }
        if (!filter_var($lien, \FILTER_VALIDATE_URL) || mb_strlen($lien) > 255) {
            return false;
        }
        $this->lienVideo = $lien;

        return true;
    }

    public function isPartage(): bool
    {
        return $this->partage;
    }

    public function setPartage(bool $partage): static
    {
        $this->partage = $partage;

        return $this;
    }

    public function getAuteurNom(): ?string
    {
        return $this->auteurNom;
    }

    public function setAuteurNom(?string $nom): static
    {
        $this->auteurNom = $nom;

        return $this;
    }
}
