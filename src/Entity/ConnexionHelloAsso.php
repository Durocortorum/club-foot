<?php

namespace App\Entity;

use App\Repository\ConnexionHelloAssoRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Compte HelloAsso du club, pour le paiement en ligne : l'argent des familles va directement au club.
 * Les identifiants viennent de l'espace HelloAsso du club (« Intégrations et API ») ; le secret est chiffré (Coffre).
 * Les jetons d'accès de l'API sont gardés ici pour ne pas se réidentifier à chaque paiement.
 */
#[ORM\Entity(repositoryClass: ConnexionHelloAssoRepository::class)]
#[ORM\UniqueConstraint(name: 'connexion_helloasso_club', columns: ['club_id'])]
class ConnexionHelloAsso implements AppartientAuClub
{
    use ClubTrait;

    /** Nombre maximum d'échéances proposées aux familles (1 : paiement en une fois seulement). */
    public const ECHEANCES = [1 => 'En une fois seulement', 2 => 'Jusqu\'à 2 fois', 3 => 'Jusqu\'à 3 fois', 4 => 'Jusqu\'à 4 fois'];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** Identifiant de l'association dans les adresses HelloAsso : helloasso.com/associations/<slug>. */
    #[ORM\Column(length: 120)]
    #[Assert\NotBlank(message: 'Indiquez l\'identifiant de votre association sur HelloAsso.')]
    #[Assert\Regex(pattern: '/^[a-z0-9-]+$/', message: 'Seulement des lettres minuscules, chiffres et tirets (ex. : fc-mon-club).')]
    private ?string $slug = null;

    #[ORM\Column(length: 120)]
    #[Assert\NotBlank(message: 'Indiquez le Client ID.')]
    private ?string $clientId = null;

    /** Client secret chiffré. */
    #[ORM\Column(type: Types::TEXT)]
    private string $secretChiffre = '';

    /** Compte de test (helloasso-sandbox.com) plutôt que le vrai compte. */
    #[ORM\Column(options: ['default' => false])]
    private bool $test = false;

    /** Nom de l'association renvoyé par HelloAsso à la vérification. */
    #[ORM\Column(length: 200, nullable: true)]
    private ?string $nomAssociation = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $verifieeLe = null;

    #[ORM\Column(type: Types::SMALLINT, options: ['default' => 1])]
    #[Assert\Choice(choices: [1, 2, 3, 4])]
    private int $echeancesMax = 1;

    /** Jeton de l'adresse de notification donnée à HelloAsso (empêche n'importe qui d'appeler cette adresse). */
    #[ORM\Column(length: 40)]
    private string $jetonNotification;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $accessToken = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $accessExpire = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $refreshToken = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $refreshExpire = null;

    public function __construct()
    {
        $this->jetonNotification = bin2hex(random_bytes(20));
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getSlug(): ?string
    {
        return $this->slug;
    }

    public function setSlug(?string $slug): static
    {
        // On accepte aussi l'adresse complète de la page HelloAsso de l'association.
        $slug = trim((string) $slug);
        if (preg_match('#helloasso(?:-sandbox)?\.com/associations/([^/?\#]+)#', $slug, $m)) {
            $slug = $m[1];
        }
        $this->slug = '' === $slug ? null : mb_strtolower($slug);

        return $this;
    }

    public function getClientId(): ?string
    {
        return $this->clientId;
    }

    public function setClientId(?string $clientId): static
    {
        $this->clientId = null === $clientId ? null : (trim($clientId) ?: null);

        return $this;
    }

    public function getSecretChiffre(): string
    {
        return $this->secretChiffre;
    }

    public function setSecretChiffre(string $secret): static
    {
        $this->secretChiffre = $secret;

        return $this;
    }

    public function hasSecret(): bool
    {
        return '' !== $this->secretChiffre;
    }

    public function isTest(): bool
    {
        return $this->test;
    }

    public function setTest(bool $test): static
    {
        $this->test = $test;

        return $this;
    }

    public function getNomAssociation(): ?string
    {
        return $this->nomAssociation;
    }

    public function getVerifieeLe(): ?\DateTimeImmutable
    {
        return $this->verifieeLe;
    }

    public function isVerifiee(): bool
    {
        return null !== $this->verifieeLe;
    }

    public function marquerVerifiee(?string $nom): static
    {
        $this->nomAssociation = $nom ? mb_substr($nom, 0, 200) : null;
        $this->verifieeLe = new \DateTimeImmutable();

        return $this;
    }

    /** Identifiants changés : à revérifier, et les anciens jetons ne valent plus. */
    public function oublierVerification(): static
    {
        $this->verifieeLe = null;
        $this->accessToken = $this->refreshToken = null;
        $this->accessExpire = $this->refreshExpire = null;

        return $this;
    }

    public function getEcheancesMax(): int
    {
        return $this->echeancesMax;
    }

    public function setEcheancesMax(int $n): static
    {
        $this->echeancesMax = max(1, min(4, $n));

        return $this;
    }

    public function getJetonNotification(): string
    {
        return $this->jetonNotification;
    }

    public function getAccessToken(): ?string
    {
        return $this->accessToken && $this->accessExpire && $this->accessExpire > new \DateTimeImmutable('+1 minute') ? $this->accessToken : null;
    }

    public function getRefreshToken(): ?string
    {
        return $this->refreshToken && $this->refreshExpire && $this->refreshExpire > new \DateTimeImmutable('+1 hour') ? $this->refreshToken : null;
    }

    public function setJetons(string $access, int $dureeSecondes, ?string $refresh): static
    {
        $this->accessToken = $access;
        $this->accessExpire = new \DateTimeImmutable('+' . max(60, $dureeSecondes) . ' seconds');
        if ($refresh) {
            // Jeton de renouvellement : valable un mois chez HelloAsso.
            $this->refreshToken = $refresh;
            $this->refreshExpire = new \DateTimeImmutable('+29 days');
        }

        return $this;
    }
}
