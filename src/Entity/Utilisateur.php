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

    /**
     * Rôles attribuables, avec leur libellé. Pour les sections à deux niveaux (voir SECTIONS),
     * ROLE_X donne la modification et inclut ROLE_X_LECTURE (hiérarchie dans security.yaml).
     */
    public const ROLES = [
        'ROLE_ADMIN' => 'Administration (paramètres)',
        'ROLE_MEDECIN' => 'Médecin du club',
        'ROLE_ECOLE_FOOT' => 'École de foot',
        'ROLE_PREFORMATION' => 'Préformation',
        'ROLE_FORMATION' => 'Formation',
        'ROLE_ELITE' => 'Élite',
        'ROLE_PRO' => 'Pro',
        'ROLE_INFIRMERIE_LECTURE' => 'Infirmerie (voir)',
        'ROLE_INFIRMERIE' => 'Infirmerie (modifier)',
        'ROLE_VISITE_MEDICALE_LECTURE' => 'Visite médicale (voir)',
        'ROLE_VISITE_MEDICALE' => 'Visite médicale (modifier)',
        'ROLE_SCOLAIRE_LECTURE' => 'Scolarité (voir)',
        'ROLE_SCOLAIRE' => 'Scolarité (modifier)',
        'ROLE_TESTS_PHYSIQUES_LECTURE' => 'Tests physiques (voir)',
        'ROLE_TESTS_PHYSIQUES' => 'Tests physiques (modifier)',
        'ROLE_RAPPORT_MATCH' => 'Rapports de match',
    ];

    /**
     * Sections médicales, très confidentielles : droits donnés par un médecin du club (ou l'administrateur)
     * pour une durée limitée, et consultation ouverte par un code reçu par e-mail (voir AccesMedical).
     */
    public const SECTIONS_MEDICALES = ['infirmerie', 'visite_medicale'];

    /** Durées proposées en donnant un droit médical : clé => libellé. « saison » : jusqu'au 30 juin. */
    public const DUREES_MEDICALES = ['7' => '1 semaine', '30' => '1 mois', '90' => '3 mois', 'saison' => 'fin de saison (30 juin)'];

    /** Sections à deux niveaux d'accès : rôle « voir » et rôle « modifier ». */
    public const SECTIONS = [
        'infirmerie' => ['libelle' => 'Infirmerie', 'lecture' => 'ROLE_INFIRMERIE_LECTURE', 'modif' => 'ROLE_INFIRMERIE'],
        'visite_medicale' => ['libelle' => 'Visite médicale', 'lecture' => 'ROLE_VISITE_MEDICALE_LECTURE', 'modif' => 'ROLE_VISITE_MEDICALE'],
        'scolarite' => ['libelle' => 'Scolarité', 'lecture' => 'ROLE_SCOLAIRE_LECTURE', 'modif' => 'ROLE_SCOLAIRE'],
        'tests_physiques' => ['libelle' => 'Tests physiques', 'lecture' => 'ROLE_TESTS_PHYSIQUES_LECTURE', 'modif' => 'ROLE_TESTS_PHYSIQUES'],
    ];

    /** Ce que chaque droit (ou section) ouvre, affiché sur le tableau des droits d'accès. */
    public const DESCRIPTIONS_ROLES = [
        'ROLE_ADMIN' => 'Utilisateurs, catégories, apparence du club. Donne aussi tous les autres droits, sauf « Médecin ».',
        'ROLE_MEDECIN' => 'Médecin du club : tout le médical (Infirmerie, Visite médicale), et donne ces droits aux autres pour une durée limitée.',
        'ROLE_ECOLE_FOOT' => 'Modifier joueurs, matchs et présences de l\'École de foot ; Recrutement.',
        'ROLE_PREFORMATION' => 'Modifier joueurs, matchs et présences de la Préformation ; Recrutement.',
        'ROLE_FORMATION' => 'Modifier joueurs, matchs et présences de la Formation ; Recrutement.',
        'ROLE_ELITE' => 'Modifier joueurs, matchs et présences de l\'Élite ; Recrutement.',
        'ROLE_PRO' => 'Modifier joueurs, matchs et présences des Pros ; Recrutement.',
        'infirmerie' => 'Page Infirmerie : blessures, contacts médicaux, renseignements médicaux de la fiche, pour tous les joueurs.',
        'visite_medicale' => 'Bloc Visite médicale de la fiche joueur : poids, taille, IMC, pointure.',
        'scolarite' => 'Page Scolarité : bulletins, discipline et sanctions.',
        'tests_physiques' => 'Bloc Tests physiques de la fiche joueur (VMA, sprints, détente…). Modifier : pour les joueurs de ses secteurs, ou tous avec l\'Infirmerie.',
        'ROLE_RAPPORT_MATCH' => 'Rédiger les rapports de tous les matchs, quel que soit le secteur.',
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

    /** Échéance du droit Infirmerie donné par un médecin (null : pas d'échéance, ex. administrateur). */
    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $infirmerieJusquAu = null;

    /** Échéance du droit Visite médicale donné par un médecin. */
    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $visiteMedicaleJusquAu = null;

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

    public function getUserIdentifier(): string
    {
        return (string) $this->email;
    }

    public function getRoles(): array
    {
        // Un compte famille n'a jamais de droit de l'encadrement, même s'il en restait en base.
        // Un droit médical arrivé à échéance ne compte plus.
        // Compte qui n'a que du médical (médecin, kiné...) : seulement l'Infirmerie, pas les pages de l'encadrement.
        $roles = match (true) {
            $this->famille => ['ROLE_FAMILLE'],
            $this->isMedicalSeul() => [...$this->rolesEnVigueur(), 'ROLE_MEDICAL_SEUL'],
            default => [...$this->rolesEnVigueur(), 'ROLE_ENCADREMENT'],
        };
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

    /**
     * Colonnes du tableau des droits, dans l'ordre : droits simples (oui / non) et sections à deux niveaux.
     *
     * @return list<array{cle: string, libelle: string, description: string, niveaux: ?array{lecture: string, modif: string}}>
     */
    public static function colonnesDroits(): array
    {
        $colonnes = [];
        foreach (['ROLE_ADMIN', 'ROLE_MEDECIN', 'ROLE_ECOLE_FOOT', 'ROLE_PREFORMATION', 'ROLE_FORMATION', 'ROLE_ELITE', 'ROLE_PRO'] as $role) {
            $colonnes[] = ['cle' => $role, 'libelle' => ['ROLE_ADMIN' => 'Administration', 'ROLE_MEDECIN' => 'Médecin'][$role] ?? self::ROLES[$role], 'description' => self::DESCRIPTIONS_ROLES[$role], 'niveaux' => null];
        }
        foreach (self::SECTIONS as $cle => $s) {
            $colonnes[] = ['cle' => $cle, 'libelle' => $s['libelle'], 'description' => self::DESCRIPTIONS_ROLES[$cle], 'niveaux' => ['lecture' => $s['lecture'], 'modif' => $s['modif']]];
        }
        $colonnes[] = ['cle' => 'ROLE_RAPPORT_MATCH', 'libelle' => self::ROLES['ROLE_RAPPORT_MATCH'], 'description' => self::DESCRIPTIONS_ROLES['ROLE_RAPPORT_MATCH'], 'niveaux' => null];

        return $colonnes;
    }

    /** Échéance d'un droit médical d'après la durée choisie (voir DUREES_MEDICALES) ; null si la durée est inconnue. */
    public static function echeanceMedicale(string $duree): ?\DateTimeImmutable
    {
        $aujourdhui = new \DateTimeImmutable('today');
        if ('saison' === $duree) {
            // Fin de saison : le 30 juin qui suit (ou aujourd'hui si c'est le 30 juin).
            $fin = $aujourdhui->setDate((int) $aujourdhui->format('Y'), 6, 30);

            return $fin < $aujourdhui ? $fin->modify('+1 year') : $fin;
        }

        return isset(self::DUREES_MEDICALES[$duree]) ? $aujourdhui->modify('+' . (int) $duree . ' days') : null;
    }

    /** @return list<string> rôles des sections médicales (voir et modifier) */
    public static function rolesMedicaux(): array
    {
        $roles = [];
        foreach (self::SECTIONS_MEDICALES as $section) {
            $roles[] = self::SECTIONS[$section]['lecture'];
            $roles[] = self::SECTIONS[$section]['modif'];
        }

        return $roles;
    }

    /** Niveau d'accès en vigueur à une section (voir SECTIONS) : 0 aucun (ou échu), 1 voir, 2 modifier. */
    public function getNiveau(string $section): int
    {
        $s = self::SECTIONS[$section];
        $roles = $this->rolesEnVigueur();

        return \in_array($s['modif'], $roles, true) ? 2 : (\in_array($s['lecture'], $roles, true) ? 1 : 0);
    }

    /**
     * Change le niveau d'accès à une section. Section médicale : $jusquAu est l'échéance du droit
     * (obligatoire pour donner un droit médical, effacée quand on le retire).
     */
    public function setNiveau(string $section, int $niveau, ?\DateTimeImmutable $jusquAu = null): static
    {
        $s = self::SECTIONS[$section];
        $roles = array_diff($this->getRolesAttribues(), [$s['lecture'], $s['modif']]);
        if ($niveau > 0) {
            $roles[] = 2 === $niveau ? $s['modif'] : $s['lecture'];
        }
        if (\in_array($section, self::SECTIONS_MEDICALES, true)) {
            $this->setEcheance($section, $niveau > 0 ? $jusquAu : null);
        }

        return $this->setRolesAttribues(array_values($roles));
    }

    /** Échéance d'un droit médical (dernier jour inclus), ou null. */
    public function getEcheance(string $section): ?\DateTimeImmutable
    {
        return match ($section) {
            'infirmerie' => $this->infirmerieJusquAu,
            'visite_medicale' => $this->visiteMedicaleJusquAu,
            default => null,
        };
    }

    public function setEcheance(string $section, ?\DateTimeImmutable $jusquAu): static
    {
        match ($section) {
            'infirmerie' => $this->infirmerieJusquAu = $jusquAu,
            'visite_medicale' => $this->visiteMedicaleJusquAu = $jusquAu,
        };

        return $this;
    }

    /** Droit médical donné mais arrivé à échéance (le dernier jour compte encore). */
    public function isEchu(string $section): bool
    {
        $fin = $this->getEcheance($section);

        return null !== $fin && $fin < new \DateTimeImmutable('today');
    }

    /**
     * Rôles enregistrés, sans les droits médicaux échus.
     *
     * @return list<string>
     */
    private function rolesEnVigueur(): array
    {
        $echus = [];
        foreach (self::SECTIONS_MEDICALES as $section) {
            if ($this->isEchu($section)) {
                $echus[] = self::SECTIONS[$section]['lecture'];
                $echus[] = self::SECTIONS[$section]['modif'];
            }
        }

        return array_values(array_diff($this->roles, $echus));
    }

    /**
     * Compte de l'encadrement dont tous les droits en vigueur sont médicaux (médecin, Infirmerie, Visite médicale) :
     * il ne voit que l'Infirmerie et les dossiers médicaux.
     */
    public function isMedicalSeul(): bool
    {
        $roles = $this->rolesEnVigueur();

        return !$this->famille && [] !== $roles && [] === array_diff($roles, ['ROLE_MEDECIN', ...self::rolesMedicaux()]);
    }

    public function isMedecin(): bool
    {
        return \in_array('ROLE_MEDECIN', $this->roles, true);
    }

    /** Donne ou retire un seul droit (tableau des droits d'accès). */
    public function basculerRole(string $role, bool $actif): static
    {
        $roles = array_diff($this->getRolesAttribues(), [$role]);

        return $this->setRolesAttribues($actif ? [...$roles, $role] : $roles);
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

    /**
     * Lien « mot de passe oublié », valable une heure. Utilise le même emplacement que le lien
     * d'activation (un seul lien valide à la fois), sans toucher à la date d'invitation.
     */
    public function jetonReinitialisation(): string
    {
        $jeton = bin2hex(random_bytes(24));
        $this->jetonActivation = self::empreinte($jeton);
        $this->jetonExpireLe = new \DateTimeImmutable('+1 hour');

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
