<?php

namespace App\Entity;

use App\Repository\UtilisateurRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Compte d'accès d'un membre du club, qui se connecte avec son adresse e-mail.
 * Les droits par section (Infirmerie, Formation...) sont des rôles Symfony.
 */
#[ORM\Entity(repositoryClass: UtilisateurRepository::class)]
#[UniqueEntity('email', message: 'Un compte existe déjà avec cette adresse e-mail.', repositoryMethod: 'tousClubs')]
class Utilisateur implements AppartientAuClub, UserInterface, PasswordAuthenticatedUserInterface
{
    use ClubTrait;

    /** Rôles attribuables, avec leur libellé. */
    public const ROLES = [
        'ROLE_ADMIN' => 'Administration (paramètres)',
        'ROLE_ECOLE_FOOT' => 'École de foot',
        'ROLE_PREFORMATION' => 'Préformation',
        'ROLE_FORMATION' => 'Formation',
        'ROLE_ELITE' => 'Élite',
        'ROLE_PRO' => 'Pro',
        'ROLE_INFIRMERIE' => 'Infirmerie',
        'ROLE_RAPPORT_MATCH' => 'Rapports de match',
        'ROLE_SCOLAIRE' => 'Scolaire',
    ];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** Adresse e-mail, utilisée pour se connecter (unique dans toute l'application). */
    #[ORM\Column(length: 180, unique: true)]
    #[Assert\NotBlank]
    #[Assert\Email(message: 'Adresse e-mail invalide.')]
    private ?string $email = null;

    #[ORM\Column(length: 80)]
    #[Assert\NotBlank]
    private ?string $nom = null;

    #[ORM\Column(length: 80)]
    #[Assert\NotBlank]
    private ?string $prenom = null;

    /** @var list<string> */
    #[ORM\Column(type: Types::JSON)]
    private array $roles = [];

    /** Mot de passe chiffré ; null tant qu'il n'a pas été défini. */
    #[ORM\Column(nullable: true)]
    private ?string $password = null;

    /**
     * Compte d'un joueur ou de ses parents : il ne voit que l'espace famille (ROLE_FAMILLE),
     * jamais les pages de l'encadrement (ROLE_ENCADREMENT).
     */
    #[ORM\Column(options: ['default' => false])]
    private bool $famille = false;

    /**
     * Joueurs suivis par un compte famille (plusieurs pour une fratrie).
     *
     * @var Collection<int, Joueur>
     */
    #[ORM\ManyToMany(targetEntity: Joueur::class)]
    #[ORM\JoinTable(name: 'utilisateur_joueur')]
    #[ORM\JoinColumn(onDelete: 'CASCADE')]
    #[ORM\InverseJoinColumn(onDelete: 'CASCADE')]
    #[ORM\OrderBy(['prenom' => 'ASC'])]
    private Collection $joueurs;

    /** Empreinte (SHA-256) du lien d'activation envoyé : le lien lui-même n'est jamais conservé. */
    #[ORM\Column(length: 64, nullable: true, unique: true)]
    private ?string $jetonActivation = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $jetonExpireLe = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $inviteLe = null;

    public function __construct()
    {
        $this->joueurs = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getEmail(): ?string
    {
        return $this->email;
    }

    public function setEmail(string $email): static
    {
        $this->email = mb_strtolower(trim($email));

        return $this;
    }

    public function getNom(): ?string
    {
        return $this->nom;
    }

    public function setNom(string $nom): static
    {
        $this->nom = $nom;

        return $this;
    }

    public function getPrenom(): ?string
    {
        return $this->prenom;
    }

    public function setPrenom(string $prenom): static
    {
        $this->prenom = $prenom;

        return $this;
    }

    public function getUserIdentifier(): string
    {
        return (string) $this->email;
    }

    public function getRoles(): array
    {
        // Un compte famille n'a jamais de droit de l'encadrement, même s'il en restait en base.
        $roles = $this->famille ? ['ROLE_FAMILLE'] : [...$this->roles, 'ROLE_ENCADREMENT'];
        $roles[] = 'ROLE_USER';

        return array_values(array_unique($roles));
    }

    /** @param list<string> $roles */
    public function setRoles(array $roles): static
    {
        $this->roles = array_values($roles);

        return $this;
    }

    /**
     * Droits attribués (sans ROLE_USER, ajouté automatiquement), pour le formulaire.
     *
     * @return list<string>
     */
    public function getRolesAttribues(): array
    {
        return array_values(array_intersect($this->roles, array_keys(self::ROLES)));
    }

    /** @param list<string> $roles */
    public function setRolesAttribues(array $roles): static
    {
        return $this->setRoles(array_values(array_intersect($roles, array_keys(self::ROLES))));
    }

    /** @return list<string> libellés des droits attribués */
    public function getLibellesRoles(): array
    {
        return array_map(fn (string $r) => self::ROLES[$r], $this->getRolesAttribues());
    }

    public function isAdmin(): bool
    {
        return \in_array('ROLE_ADMIN', $this->roles, true);
    }

    public function getPassword(): ?string
    {
        return $this->password;
    }

    public function setPassword(?string $password): static
    {
        $this->password = $password;

        return $this;
    }

    public function eraseCredentials(): void
    {
    }

    public function isFamille(): bool
    {
        return $this->famille;
    }

    public function setFamille(bool $famille): static
    {
        $this->famille = $famille;
        if ($famille) {
            $this->roles = [];
        }

        return $this;
    }

    /** @return Collection<int, Joueur> */
    public function getJoueurs(): Collection
    {
        return $this->joueurs;
    }

    public function addJoueur(Joueur $joueur): static
    {
        if (!$this->joueurs->contains($joueur)) {
            $this->joueurs->add($joueur);
        }

        return $this;
    }

    public function removeJoueur(Joueur $joueur): static
    {
        $this->joueurs->removeElement($joueur);

        return $this;
    }

    public function suit(Joueur $joueur): bool
    {
        return $this->joueurs->contains($joueur);
    }

    /**
     * Prépare un nouveau lien d'activation (valable $jours jours) et renvoie le jeton à mettre dans le lien.
     * Le précédent lien, s'il y en avait un, ne fonctionne plus.
     */
    public function nouveauJeton(int $jours = 14): string
    {
        $jeton = bin2hex(random_bytes(24));
        $this->jetonActivation = self::empreinte($jeton);
        $this->jetonExpireLe = new \DateTimeImmutable("+$jours days");
        $this->inviteLe = new \DateTimeImmutable();

        return $jeton;
    }

    public static function empreinte(string $jeton): string
    {
        return hash('sha256', $jeton);
    }

    public function isJetonValide(): bool
    {
        return null !== $this->jetonActivation && $this->jetonExpireLe > new \DateTimeImmutable();
    }

    public function effacerJeton(): static
    {
        $this->jetonActivation = null;
        $this->jetonExpireLe = null;

        return $this;
    }

    public function getJetonExpireLe(): ?\DateTimeImmutable
    {
        return $this->jetonExpireLe;
    }

    public function getInviteLe(): ?\DateTimeImmutable
    {
        return $this->inviteLe;
    }

    /** « actif » (mot de passe choisi), « invite » (lien en cours de validité) ou « expire ». */
    public function getStatutAcces(): string
    {
        return $this->password ? 'actif' : ($this->isJetonValide() ? 'invite' : 'expire');
    }

    public function __toString(): string
    {
        return trim($this->prenom . ' ' . $this->nom);
    }
}
