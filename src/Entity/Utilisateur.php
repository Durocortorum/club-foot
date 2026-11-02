<?php

namespace App\Entity;

use App\Repository\UtilisateurRepository;
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
        $roles = $this->roles;
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

    public function __toString(): string
    {
        return trim($this->prenom . ' ' . $this->nom);
    }
}
