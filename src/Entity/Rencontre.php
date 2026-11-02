<?php

namespace App\Entity;

use App\Repository\RencontreRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * Rapport de match (table Access « Tbl RapportMatch2 »). Le score est noté
 * du point de vue des équipes : domicile puis extérieur.
 */
#[ORM\Entity(repositoryClass: RencontreRepository::class)]
#[ORM\Index(columns: ['date'])]
class Rencontre implements AppartientAuClub
{
    use ClubTrait;

    public const COMPETITIONS = ['Championnat', 'Coupe', 'Amical', 'Tournoi'];
    public const TERRAINS = ['Synthétique', 'Herbe Souple', 'Herbe Gras', 'Herbe Bosselé', 'Herbe Sec', 'Stabilisé'];

    /** @var list<string> voir reconnaitreClub() */
    private static array $nomsClub = [];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    #[Assert\NotNull]
    private ?\DateTimeImmutable $date = null;

    #[ORM\Column(length: 10, nullable: true)]
    private ?string $heure = null;

    #[ORM\ManyToOne]
    #[Assert\NotNull]
    private ?Categorie $categorie = null;

    /** Libellé précis de l'équipe engagée (ex. : « U13 Excellence »). */
    #[ORM\Column(length: 30, nullable: true)]
    private ?string $equipe = null;

    #[ORM\Column(length: 30, nullable: true)]
    private ?string $competition = null;

    #[ORM\Column(length: 80)]
    #[Assert\NotBlank]
    private ?string $domicile = null;

    #[ORM\Column(length: 80)]
    #[Assert\NotBlank]
    private ?string $exterieur = null;

    #[ORM\Column(nullable: true)]
    #[Assert\PositiveOrZero]
    private ?int $butsDomicile = null;

    #[ORM\Column(nullable: true)]
    #[Assert\PositiveOrZero]
    private ?int $butsExterieur = null;

    #[ORM\Column(nullable: true)]
    #[Assert\PositiveOrZero]
    private ?int $butsDomicileMiTemps = null;

    #[ORM\Column(nullable: true)]
    #[Assert\PositiveOrZero]
    private ?int $butsExterieurMiTemps = null;

    #[ORM\Column(length: 120, nullable: true)]
    private ?string $lieu = null;

    #[ORM\Column(length: 40, nullable: true)]
    private ?string $terrain = null;

    #[ORM\Column(length: 40, nullable: true)]
    private ?string $meteo = null;

    #[ORM\Column(length: 40, nullable: true)]
    private ?string $vent = null;

    #[ORM\Column(length: 10, nullable: true)]
    private ?string $temperature = null;

    /** Organisation de jeu (ex. : « 4 4 2 »). */
    #[ORM\Column(length: 20, nullable: true)]
    private ?string $systeme = null;

    #[ORM\Column(nullable: true)]
    #[Assert\Range(min: 0, max: 10)]
    private ?float $note = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $rapport = null;

    /** @var Collection<int, Participation> */
    #[ORM\OneToMany(targetEntity: Participation::class, mappedBy: 'rencontre', cascade: ['persist'], orphanRemoval: true)]
    #[ORM\OrderBy(['numero' => 'ASC'])]
    #[Assert\Valid]
    private Collection $participations;

    /** @var Collection<int, But> */
    #[ORM\OneToMany(targetEntity: But::class, mappedBy: 'rencontre', cascade: ['persist'], orphanRemoval: true)]
    #[ORM\OrderBy(['minute' => 'ASC'])]
    #[Assert\Valid]
    private Collection $buts;

    /** @var Collection<int, Remplacement> */
    #[ORM\OneToMany(targetEntity: Remplacement::class, mappedBy: 'rencontre', cascade: ['persist'], orphanRemoval: true)]
    #[ORM\OrderBy(['minute' => 'ASC'])]
    #[Assert\Valid]
    private Collection $remplacements;

    public function __construct()
    {
        $this->participations = new ArrayCollection();
        $this->buts = new ArrayCollection();
        $this->remplacements = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getDate(): ?\DateTimeImmutable
    {
        return $this->date;
    }

    public function setDate(?\DateTimeImmutable $date): static
    {
        $this->date = $date;

        return $this;
    }

    public function getHeure(): ?string
    {
        return $this->heure;
    }

    public function setHeure(?string $heure): static
    {
        $this->heure = $heure;

        return $this;
    }

    public function getCategorie(): ?Categorie
    {
        return $this->categorie;
    }

    public function setCategorie(?Categorie $categorie): static
    {
        $this->categorie = $categorie;

        return $this;
    }

    public function getEquipe(): ?string
    {
        return $this->equipe;
    }

    public function setEquipe(?string $equipe): static
    {
        $this->equipe = $equipe;

        return $this;
    }

    /** Équipe affichée : le libellé précis s'il existe, sinon la catégorie. */
    public function getEquipeAffichee(): string
    {
        return $this->equipe ?? (string) $this->categorie;
    }

    public function getCompetition(): ?string
    {
        return $this->competition;
    }

    public function setCompetition(?string $competition): static
    {
        $this->competition = $competition;

        return $this;
    }

    public function getDomicile(): ?string
    {
        return $this->domicile;
    }

    public function setDomicile(?string $domicile): static
    {
        $this->domicile = $domicile;

        return $this;
    }

    public function getExterieur(): ?string
    {
        return $this->exterieur;
    }

    public function setExterieur(?string $exterieur): static
    {
        $this->exterieur = $exterieur;

        return $this;
    }

    public function getButsDomicile(): ?int
    {
        return $this->butsDomicile;
    }

    public function setButsDomicile(?int $butsDomicile): static
    {
        $this->butsDomicile = $butsDomicile;

        return $this;
    }

    public function getButsExterieur(): ?int
    {
        return $this->butsExterieur;
    }

    public function setButsExterieur(?int $butsExterieur): static
    {
        $this->butsExterieur = $butsExterieur;

        return $this;
    }

    public function getButsDomicileMiTemps(): ?int
    {
        return $this->butsDomicileMiTemps;
    }

    public function setButsDomicileMiTemps(?int $butsDomicileMiTemps): static
    {
        $this->butsDomicileMiTemps = $butsDomicileMiTemps;

        return $this;
    }

    public function getButsExterieurMiTemps(): ?int
    {
        return $this->butsExterieurMiTemps;
    }

    public function setButsExterieurMiTemps(?int $butsExterieurMiTemps): static
    {
        $this->butsExterieurMiTemps = $butsExterieurMiTemps;

        return $this;
    }

    public function getLieu(): ?string
    {
        return $this->lieu;
    }

    public function setLieu(?string $lieu): static
    {
        $this->lieu = $lieu;

        return $this;
    }

    public function getTerrain(): ?string
    {
        return $this->terrain;
    }

    public function setTerrain(?string $terrain): static
    {
        $this->terrain = $terrain;

        return $this;
    }

    public function getMeteo(): ?string
    {
        return $this->meteo;
    }

    public function setMeteo(?string $meteo): static
    {
        $this->meteo = $meteo;

        return $this;
    }

    public function getVent(): ?string
    {
        return $this->vent;
    }

    public function setVent(?string $vent): static
    {
        $this->vent = $vent;

        return $this;
    }

    public function getTemperature(): ?string
    {
        return $this->temperature;
    }

    public function setTemperature(?string $temperature): static
    {
        $this->temperature = $temperature;

        return $this;
    }

    public function getSysteme(): ?string
    {
        return $this->systeme;
    }

    public function setSysteme(?string $systeme): static
    {
        $this->systeme = $systeme;

        return $this;
    }

    public function getNote(): ?float
    {
        return $this->note;
    }

    public function setNote(?float $note): static
    {
        $this->note = $note;

        return $this;
    }

    public function getRapport(): ?string
    {
        return $this->rapport;
    }

    public function setRapport(?string $rapport): static
    {
        $this->rapport = $rapport;

        return $this;
    }

    /** @return Collection<int, Participation> */
    public function getParticipations(): Collection
    {
        return $this->participations;
    }

    public function addParticipation(Participation $participation): static
    {
        if (!$this->participations->contains($participation)) {
            $this->participations->add($participation);
            $participation->setRencontre($this);
        }

        return $this;
    }

    public function removeParticipation(Participation $participation): static
    {
        $this->participations->removeElement($participation);

        return $this;
    }

    /** @return Collection<int, But> */
    public function getButs(): Collection
    {
        return $this->buts;
    }

    public function addBut(But $but): static
    {
        if (!$this->buts->contains($but)) {
            $this->buts->add($but);
            $but->setRencontre($this);
        }

        return $this;
    }

    public function removeBut(But $but): static
    {
        $this->buts->removeElement($but);

        return $this;
    }

    /** @return Collection<int, Remplacement> */
    public function getRemplacements(): Collection
    {
        return $this->remplacements;
    }

    public function addRemplacement(Remplacement $remplacement): static
    {
        if (!$this->remplacements->contains($remplacement)) {
            $this->remplacements->add($remplacement);
            $remplacement->setRencontre($this);
        }

        return $this;
    }

    public function removeRemplacement(Remplacement $remplacement): static
    {
        $this->remplacements->removeElement($remplacement);

        return $this;
    }

    /** Le club joue-t-il à domicile ? */
    public function isADomicile(): bool
    {
        return self::estLeClub($this->domicile) || !self::estLeClub($this->exterieur);
    }

    /** Nom qui désigne le club dans les équipes d'un match : celui de l'utilisateur connecté (voir ClubSubscriber). */
    public static function reconnaitreClub(string $nom): void
    {
        self::$nomsClub = array_filter([mb_strtolower(trim($nom))]);
    }

    private static function estLeClub(?string $equipe): bool
    {
        foreach (self::$nomsClub as $nom) {
            if (false !== mb_stripos((string) $equipe, $nom)) {
                return true;
            }
        }

        return false;
    }

    public function getAdversaire(): ?string
    {
        return $this->isADomicile() ? $this->exterieur : $this->domicile;
    }

    public function getButsPour(): ?int
    {
        return $this->isADomicile() ? $this->butsDomicile : $this->butsExterieur;
    }

    public function getButsContre(): ?int
    {
        return $this->isADomicile() ? $this->butsExterieur : $this->butsDomicile;
    }

    /** « V », « N » ou « D » du point de vue du Stade de Reims, null si le score manque. */
    public function getResultat(): ?string
    {
        $pour = $this->getButsPour();
        $contre = $this->getButsContre();
        if (null === $pour || null === $contre) {
            return null;
        }

        return $pour <=> $contre ? ($pour > $contre ? 'V' : 'D') : 'N';
    }

    /** Un joueur ne peut pas se faire la passe décisive, ni se remplacer lui-même. */
    #[Assert\Callback]
    public function validerDoublons(ExecutionContextInterface $context): void
    {
        foreach ($this->buts as $i => $b) {
            if ($b->getButeur() && $b->getButeur() === $b->getPasseur()) {
                $context->buildViolation('Le buteur ne peut pas être aussi le passeur.')->atPath("buts[$i].passeur")->addViolation();
            }
        }
        foreach ($this->remplacements as $i => $r) {
            if ($r->getSortant() && $r->getSortant() === $r->getEntrant()) {
                $context->buildViolation('Le joueur qui entre doit être différent de celui qui sort.')->atPath("remplacements[$i].entrant")->addViolation();
            }
        }
    }

    /**
     * Dans l'ordre des minutes, un joueur ne sort que s'il est sur le terrain : titulaire
     * (n° 1 à 11, hors « Non utilisé ») ou entré, et pas ressorti depuis. Il peut ensuite
     * revenir (règle des jeunes), mais pas entrer deux fois sans être sorti.
     * Si la feuille n'indique aucun titulaire, on vérifie seulement l'alternance sortie / entrée.
     */
    #[Assert\Callback]
    public function validerChronologie(ExecutionContextInterface $context): void
    {
        $remplacements = $this->remplacements->toArray();
        $ordre = array_flip(array_keys($remplacements));
        uksort($remplacements, fn ($a, $b) => [$remplacements[$a]->getMinute() ?? \PHP_INT_MAX, $ordre[$a]] <=> [$remplacements[$b]->getMinute() ?? \PHP_INT_MAX, $ordre[$b]]);

        $titulaires = [];
        foreach ($this->participations as $p) {
            if ($p->getJoueur() && $p->isTitulaire()) {
                $titulaires[spl_object_id($p->getJoueur())] = true;
            }
        }

        /** @var array<int, array{0: string, 1: ?int}> $dernier sens et minute du dernier mouvement de chaque joueur */
        $dernier = [];
        $erreur = function (string $message, Joueur $joueur, ?int $minute, string $chemin) use ($context): void {
            $context->buildViolation($message)
                ->setParameter('{{ joueur }}', $joueur->getNomComplet())
                ->setParameter('{{ minute }}', null !== $minute ? ' à la ' . $minute . 'e minute' : '')
                ->atPath($chemin)
                ->addViolation();
        };

        foreach ($remplacements as $i => $r) {
            if ($s = $r->getSortant()) {
                $id = spl_object_id($s);
                if ('sortant' === ($dernier[$id][0] ?? null)) {
                    $erreur('{{ joueur }} est déjà sorti{{ minute }} et n\'est pas rentré depuis.', $s, $dernier[$id][1], "remplacements[$i].sortant");
                } elseif (!isset($dernier[$id]) && $titulaires && !isset($titulaires[$id])) {
                    $erreur('{{ joueur }} est sur le banc : il n\'est pas titulaire et n\'est pas entré avant cette sortie.', $s, null, "remplacements[$i].sortant");
                }
                $dernier[$id] = ['sortant', $r->getMinute()];
            }
            if ($e = $r->getEntrant()) {
                $id = spl_object_id($e);
                if ('entrant' === ($dernier[$id][0] ?? null)) {
                    $erreur('{{ joueur }} est déjà entré{{ minute }} et n\'est pas ressorti depuis.', $e, $dernier[$id][1], "remplacements[$i].entrant");
                }
                $dernier[$id] = ['entrant', $r->getMinute()];
            }
        }
    }

    /** Buteurs, passeurs et remplaçants doivent figurer sur la feuille de match, si elle est remplie. */
    #[Assert\Callback]
    public function validerConvocations(ExecutionContextInterface $context): void
    {
        $convoques = [];
        foreach ($this->participations as $p) {
            if ($p->getJoueur()) {
                $convoques[spl_object_id($p->getJoueur())] = true;
            }
        }
        if (!$convoques) {
            return;
        }

        $verifier = function (?Joueur $joueur, string $chemin) use ($convoques, $context): void {
            if ($joueur && !isset($convoques[spl_object_id($joueur)])) {
                $context->buildViolation('{{ joueur }} n\'est pas sur la feuille de match.')
                    ->setParameter('{{ joueur }}', $joueur->getNomComplet())
                    ->atPath($chemin)
                    ->addViolation();
            }
        };
        foreach ($this->buts as $i => $b) {
            $verifier($b->getButeur(), "buts[$i].buteur");
            $verifier($b->getPasseur(), "buts[$i].passeur");
        }
        foreach ($this->remplacements as $i => $r) {
            $verifier($r->getSortant(), "remplacements[$i].sortant");
            $verifier($r->getEntrant(), "remplacements[$i].entrant");
        }
    }

    public function getSaison(): ?string
    {
        return $this->date ? self::saisonDe($this->date) : null;
    }

    /** Une saison va du 1er juillet au 30 juin (ex. : « 2013/2014 »). */
    public static function saisonDe(\DateTimeInterface $date): string
    {
        $annee = (int) $date->format('Y') - ((int) $date->format('n') < 7 ? 1 : 0);

        return $annee . '/' . ($annee + 1);
    }

    /** @return array{0: \DateTimeImmutable, 1: \DateTimeImmutable} premier et dernier jour de la saison */
    public static function bornesSaison(string $saison): array
    {
        $annee = (int) substr($saison, 0, 4);

        return [new \DateTimeImmutable("$annee-07-01"), new \DateTimeImmutable(($annee + 1) . '-06-30')];
    }

    public function __toString(): string
    {
        return trim(sprintf('%s – %s', $this->domicile, $this->exterieur));
    }
}
