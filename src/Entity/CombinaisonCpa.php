<?php

namespace App\Entity;

use App\Repository\CombinaisonCpaRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Combinaison de coup de pied arrêté de la bibliothèque du club (corner, coup franc, touche ; offensive ou
 * défensive) : schéma animé, rôle de chaque joueur (tireur, finition, écran, appel, soutien, repli…) et consignes.
 * Se copie dans le tableau tactique d'un match ou dans une séance d'entraînement.
 */
#[ORM\Entity(repositoryClass: CombinaisonCpaRepository::class)]
class CombinaisonCpa implements AppartientAuClub
{
    use ClubTrait;

    public const TYPES = ['corner' => ['Corners', '🚩'], 'coup_franc' => ['Coups francs', '🧱'], 'touche' => ['Touches', '🙌']];
    public const COTES = ['offensif' => 'Offensif', 'defensif' => 'Défensif'];
    /** Rôles proposés (on peut en écrire un autre). */
    public const ROLES = [
        'Tireur', 'Finition 1er poteau', 'Finition 2e poteau', 'Finition au point de penalty', 'Appel', 'Appel en profondeur', 'Écran',
        'Leurre', 'Soutien (zone de reprise)', 'Repli', 'Marquage individuel', 'Marquage de zone', 'Gardien', 'Mur',
    ];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 100)]
    private string $titre = '';

    #[ORM\Column(length: 12)]
    private string $type = 'corner';

    #[ORM\Column(length: 10)]
    private string $cote = 'offensif';

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $consignes = null;

    /** @var array<string, mixed> voir SchemaTerrain */
    #[ORM\Column(name: 'dessin', type: Types::JSON)]
    private array $schema = ['terrain' => 'demi', 'objets' => []];

    /** @var list<array{n: string, role: string, detail: ?string}> rôle de chaque joueur, par numéro sur le schéma */
    #[ORM\Column(type: Types::JSON)]
    private array $roles = [];

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $modifieLe = null;

    #[ORM\Column(length: 170, nullable: true)]
    private ?string $modifieParNom = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTitre(): string
    {
        return $this->titre;
    }

    public function setTitre(?string $titre): static
    {
        $this->titre = mb_substr(trim((string) $titre), 0, 100);

        return $this;
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function setType(?string $type): static
    {
        $this->type = isset(self::TYPES[$type]) ? $type : 'corner';

        return $this;
    }

    public function getTypeLibelle(): string
    {
        return rtrim(self::TYPES[$this->type][0], 's');
    }

    public function getIcone(): string
    {
        return self::TYPES[$this->type][1];
    }

    public function getCote(): string
    {
        return $this->cote;
    }

    public function setCote(?string $cote): static
    {
        $this->cote = isset(self::COTES[$cote]) ? $cote : 'offensif';

        return $this;
    }

    public function getCoteLibelle(): string
    {
        return self::COTES[$this->cote];
    }

    public function getConsignes(): ?string
    {
        return $this->consignes;
    }

    public function setConsignes(?string $consignes): static
    {
        $consignes = trim((string) $consignes);
        $this->consignes = '' === $consignes ? null : mb_substr($consignes, 0, 3000);

        return $this;
    }

    /** @return array<string, mixed> */
    public function getSchema(): array
    {
        return $this->schema;
    }

    /** @param array<string, mixed> $schema */
    public function setSchema(array $schema): static
    {
        $this->schema = SchemaTerrain::nettoyer($schema, 'demi');

        return $this;
    }

    /** @return list<array{n: string, role: string, detail: ?string}> */
    public function getRoles(): array
    {
        return $this->roles;
    }

    /**
     * @param iterable<array<string, mixed>> $roles lignes numéro / rôle / précision ; les lignes sans rôle sont ignorées
     */
    public function setRoles(iterable $roles): static
    {
        $propres = [];
        foreach ($roles as $r) {
            $role = mb_substr(trim((string) ($r['role'] ?? '')), 0, 60);
            if ('' === $role) {
                continue;
            }
            $detail = mb_substr(trim((string) ($r['detail'] ?? '')), 0, 80);
            $propres[] = ['n' => mb_substr(trim((string) ($r['n'] ?? '')), 0, 3), 'role' => $role, 'detail' => '' === $detail ? null : $detail];
        }
        $this->roles = $propres;

        return $this;
    }

    /** Consignes complètes à copier (tableau tactique, exercice) : les consignes, puis le rôle de chaque joueur. */
    public function texteComplet(): string
    {
        $lignes = array_map(fn (array $r) => ('' !== $r['n'] ? $r['n'] . ' : ' : '') . $r['role'] . ($r['detail'] ? ' (' . $r['detail'] . ')' : ''), $this->roles);

        return trim(($this->consignes ?? '') . ($lignes ? "\n\nRôles :\n" . implode("\n", $lignes) : ''));
    }

    public function marquerModifie(Utilisateur $par): static
    {
        $this->modifieLe = new \DateTimeImmutable();
        $this->modifieParNom = trim($par->getPrenom() . ' ' . $par->getNom());

        return $this;
    }

    public function getModifieLe(): ?\DateTimeImmutable
    {
        return $this->modifieLe;
    }

    public function getModifieParNom(): ?string
    {
        return $this->modifieParNom;
    }
}
