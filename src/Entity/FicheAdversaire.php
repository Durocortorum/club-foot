<?php

namespace App\Entity;

use App\Repository\FicheAdversaireRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Analyse d'un adversaire par une équipe du club : notes de l'éducateur (points forts, points faibles, à retenir)
 * et préparation du prochain match contre lui. L'adversaire est reconnu d'un match à l'autre par le cœur de son nom
 * (« US VALLÉE VERTE » et « VALLÉE VERTE » : même adversaire, voir Rencontre::coeurDuNom).
 */
#[ORM\Entity(repositoryClass: FicheAdversaireRepository::class)]
#[ORM\UniqueConstraint(columns: ['club_id', 'categorie_id', 'cle'])]
class FicheAdversaire implements AppartientAuClub
{
    use ClubTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Categorie $categorie;

    /** Nom de l'adversaire ramené à une forme comparable (voir self::cleDe). */
    #[ORM\Column(length: 80)]
    private string $cle;

    /** Nom affiché, tel qu'il apparaît sur le dernier match. */
    #[ORM\Column(length: 80)]
    private string $nom;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $pointsForts = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $pointsFaibles = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $aRetenir = null;

    /** Préparation du prochain match : organisation prévue, consignes, ajustements. */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $preparation = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $modifieLe = null;

    #[ORM\Column(length: 170, nullable: true)]
    private ?string $modifieParNom = null;

    public function __construct(Categorie $categorie, string $nom)
    {
        $this->categorie = $categorie;
        $this->nom = mb_substr($nom, 0, 80);
        $this->cle = self::cleDe($nom);
    }

    /** « US VALLÉE VERTE » → « vallee verte » ; le nom entier si l'on n'y trouve que des mots génériques. */
    public static function cleDe(?string $nom): string
    {
        $cle = Rencontre::coeurDuNom($nom);

        return mb_substr('' !== $cle ? $cle : mb_strtolower(trim((string) $nom)), 0, 80);
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCategorie(): Categorie
    {
        return $this->categorie;
    }

    public function getCle(): string
    {
        return $this->cle;
    }

    public function getNom(): string
    {
        return $this->nom;
    }

    public function setNom(string $nom): static
    {
        $this->nom = mb_substr($nom, 0, 80);

        return $this;
    }

    public function getPointsForts(): ?string
    {
        return $this->pointsForts;
    }

    public function setPointsForts(?string $texte): static
    {
        $this->pointsForts = self::propre($texte);

        return $this;
    }

    public function getPointsFaibles(): ?string
    {
        return $this->pointsFaibles;
    }

    public function setPointsFaibles(?string $texte): static
    {
        $this->pointsFaibles = self::propre($texte);

        return $this;
    }

    public function getARetenir(): ?string
    {
        return $this->aRetenir;
    }

    public function setARetenir(?string $texte): static
    {
        $this->aRetenir = self::propre($texte);

        return $this;
    }

    public function getPreparation(): ?string
    {
        return $this->preparation;
    }

    public function setPreparation(?string $texte): static
    {
        $this->preparation = self::propre($texte);

        return $this;
    }

    public function aDesNotes(): bool
    {
        return null !== $this->pointsForts || null !== $this->pointsFaibles || null !== $this->aRetenir;
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

    private static function propre(?string $texte): ?string
    {
        $texte = trim((string) $texte);

        return '' === $texte ? null : mb_substr($texte, 0, 5000);
    }
}
