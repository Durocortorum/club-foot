<?php

namespace App\Entity;

use App\Repository\AbonnementPushRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Téléphone (ou navigateur) d'un utilisateur abonné aux notifications : adresse du service de notification du
 * navigateur et clés de chiffrement qu'il a données. Un utilisateur peut en avoir plusieurs (téléphone, tablette…).
 */
#[ORM\Entity(repositoryClass: AbonnementPushRepository::class)]
#[ORM\UniqueConstraint(name: 'abonnement_push_adresse', columns: ['empreinte'])]
class AbonnementPush
{
    use ClubTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Utilisateur $utilisateur = null;

    /** Adresse fournie par le navigateur (service de Google, Apple, Mozilla…). */
    #[ORM\Column(type: Types::TEXT)]
    private string $adresse;

    /** SHA-256 de l'adresse : clé unique (l'adresse est trop longue pour un index). */
    #[ORM\Column(length: 64)]
    private string $empreinte;

    #[ORM\Column(length: 120)]
    private string $cleP256dh;

    #[ORM\Column(length: 60)]
    private string $cleAuth;

    /** Appareil, pour que l'utilisateur s'y retrouve (« iPhone », « Android »…). */
    #[ORM\Column(length: 60, nullable: true)]
    private ?string $appareil = null;

    #[ORM\Column]
    private \DateTimeImmutable $creeLe;

    public function __construct(Utilisateur $utilisateur, string $adresse, string $cleP256dh, string $cleAuth, ?string $appareil)
    {
        $this->utilisateur = $utilisateur;
        $this->setClub($utilisateur->getClub());
        $this->setAdresse($adresse);
        $this->cleP256dh = $cleP256dh;
        $this->cleAuth = $cleAuth;
        $this->appareil = $appareil ? mb_substr($appareil, 0, 60) : null;
        $this->creeLe = new \DateTimeImmutable();
    }

    public static function empreinteDe(string $adresse): string
    {
        return hash('sha256', $adresse);
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUtilisateur(): ?Utilisateur
    {
        return $this->utilisateur;
    }

    /** Même téléphone repris par un autre compte (parent qui se reconnecte avec le compte de l'autre parent). */
    public function setUtilisateur(Utilisateur $utilisateur): static
    {
        $this->utilisateur = $utilisateur;
        $this->setClub($utilisateur->getClub());

        return $this;
    }

    public function getAdresse(): string
    {
        return $this->adresse;
    }

    private function setAdresse(string $adresse): void
    {
        $this->adresse = $adresse;
        $this->empreinte = self::empreinteDe($adresse);
    }

    public function getCleP256dh(): string
    {
        return $this->cleP256dh;
    }

    public function getCleAuth(): string
    {
        return $this->cleAuth;
    }

    public function setCles(string $cleP256dh, string $cleAuth): static
    {
        $this->cleP256dh = $cleP256dh;
        $this->cleAuth = $cleAuth;

        return $this;
    }

    public function getAppareil(): ?string
    {
        return $this->appareil;
    }

    public function getCreeLe(): \DateTimeImmutable
    {
        return $this->creeLe;
    }
}
