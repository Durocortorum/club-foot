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
    public const METEOS = ['Soleil', 'Nuageux', 'Couvert', 'Pluie', 'Forte pluie', 'Orage', 'Brouillard', 'Neige', 'Gel', 'Forte chaleur'];
    public const VENTS = ['Néant', 'Faible', 'Modéré', 'Fort', 'Rafale', 'Violent'];
    /** Organisations de jeu par format (joueurs de champ, sans le gardien). */
    public const SYSTEMES = [
        'Foot à 11' => ['4 4 2', '4 4 2 losange', '4 3 3', '4 2 3 1', '4 1 4 1', '4 5 1', '4 1 3 2', '4 3 1 2', '4 3 2 1', '3 5 2', '3 4 3', '3 4 1 2', '5 3 2', '5 4 1'],
        'Foot à 9' => ['3 3 2', '3 4 1', '2 4 2', '3 1 3 1', '3 2 3', '4 3 1'],
        'Foot à 8' => ['3 3 1', '3 2 2', '2 3 2', '2 4 1', '3 1 3', '2 3 1 1'],
    ];

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
    #[ORM\OrderBy(['minute' => 'ASC', 'additionnel' => 'ASC'])]
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
        $this->equipe = null === $equipe ? null : mb_strtoupper(trim($equipe));

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
        $this->domicile = null === $domicile ? null : mb_strtoupper(trim($domicile));

        return $this;
    }

    public function getExterieur(): ?string
    {
        return $this->exterieur;
    }

    public function setExterieur(?string $exterieur): static
    {
        $this->exterieur = null === $exterieur ? null : mb_strtoupper(trim($exterieur));

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

    /**
     * Nom qui désigne le club dans les équipes d'un match : celui de l'utilisateur connecté (voir ClubSubscriber).
     * Le nom complet, son cœur sans les mots génériques (« Stade de Reims » → « reims ») ou ses initiales
     * (« sdr ») : les matchs saisis s'écrivent souvent « METZ – REIMS » ou « tinqueux – sdr ».
     */
    public static function reconnaitreClub(string $nom): void
    {
        $complet = self::normaliser($nom);
        $mots = explode(' ', $complet);
        $coeur = implode(' ', array_diff($mots, self::MOTS_GENERIQUES));
        // Initiales seulement à partir de 3 lettres : 2 lettres prêteraient à confusion (« cp », « as »…).
        $initiales = \count($mots) >= 3 ? implode('', array_map(fn (string $m) => $m[0], $mots)) : '';
        self::$nomsClub = array_values(array_unique(array_filter([$complet, $coeur, $initiales])));
    }

    /** Mots qui ne désignent pas un club en particulier. */
    private const MOTS_GENERIQUES = [
        'stade', 'de', 'du', 'des', 'la', 'le', 'les', 'l', 'd', 'fc', 'as', 'us', 'sc', 'ac', 'rc', 'es', 'cs', 'ca',
        'af', 'js', 'club', 'football', 'foot', 'olympique', 'sporting', 'racing', 'union', 'association', 'entente',
        'jeunesse', 'sportive', 'avenir', 'etoile',
    ];

    /** Minuscules sans accents, mots séparés par une espace (« Château-Porcien » → « chateau porcien »). */
    private static function normaliser(?string $texte): string
    {
        $texte = mb_strtolower((string) $texte);
        $texte = strtr($texte, ['à' => 'a', 'â' => 'a', 'ä' => 'a', 'ç' => 'c', 'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e',
            'î' => 'i', 'ï' => 'i', 'ô' => 'o', 'ö' => 'o', 'ù' => 'u', 'û' => 'u', 'ü' => 'u', 'ÿ' => 'y', 'œ' => 'oe', 'æ' => 'ae']);

        return trim((string) preg_replace('/[^a-z0-9]+/', ' ', $texte));
    }

    /**
     * Nom d'équipe sans les mots génériques (« FC SOCHAUX MONTB. » → « sochaux montb ») :
     * pour retrouver un adversaire d'un site à l'autre (classement, calendrier).
     */
    public static function coeurDuNom(?string $equipe): string
    {
        $mots = array_diff(explode(' ', self::normaliser($equipe)), self::MOTS_GENERIQUES);

        return implode(' ', array_filter($mots, fn (string $m) => !preg_match('/^(u\d{1,2}|nat|\d)$/', $m)));
    }

    /** L'équipe désigne-t-elle le club de l'utilisateur connecté (« STADE DE REIMS », « REIMS B », « SDR ») ? */
    public static function estLeClub(?string $equipe): bool
    {
        $equipe = ' ' . self::normaliser($equipe) . ' ';
        foreach (self::$nomsClub as $nom) {
            // Mots entiers : « reims » reconnaît « REIMS B » mais pas « Reimsbach ».
            if (str_contains($equipe, " $nom ")) {
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

    /** Un joueur figure une seule fois sur la feuille, ne se fait pas la passe décisive, ni ne se remplace lui-même. */
    #[Assert\Callback]
    public function validerDoublons(ExecutionContextInterface $context): void
    {
        $vus = [];
        foreach ($this->participations as $i => $p) {
            $joueur = $p->getJoueur();
            if ($joueur && isset($vus[spl_object_id($joueur)])) {
                $context->buildViolation('{{ joueur }} est déjà sur la feuille de match.')
                    ->setParameter('{{ joueur }}', $joueur->getNomComplet())
                    ->atPath("participations[$i].joueur")
                    ->addViolation();
            }
            $vus[spl_object_id($joueur ?? $p)] = true;
        }
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

    /** Buteurs, passeurs et remplaçants doivent figurer sur la feuille de match. */
    #[Assert\Callback]
    public function validerConvocations(ExecutionContextInterface $context): void
    {
        $convoques = [];
        foreach ($this->participations as $p) {
            if ($p->getJoueur()) {
                $convoques[spl_object_id($p->getJoueur())] = true;
            }
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

    /**
     * Moments où chaque joueur de la feuille est sur le terrain, d'après les remplacements et les expulsions
     * (carton rouge avec sa minute). Au coup d'envoi : les joueurs qui n'entrent pas en cours de match,
     * hors banc déclaré (poste « Non utilisé », n° au-delà de 11). Même règle que feuille_controller.js.
     *
     * @return array<int, list<array{float, float}>> intervalles [début, fin] par joueur (spl_object_id)
     */
    public function presences(): array
    {
        $evenements = [];
        foreach ($this->remplacements as $r) {
            if (null !== $r->getMinute()) {
                $evenements[] = [(float) $r->getMinute(), $r->getSortant(), $r->getEntrant()];
            }
        }
        foreach ($this->participations as $p) {
            if (\in_array($p->getCarton(), Participation::CARTONS_ROUGES, true) && null !== ($m = self::ordreMinute($p->getMinuteCarton()))) {
                $evenements[] = [$m, $p->getJoueur(), null];
            }
        }
        usort($evenements, fn (array $a, array $b) => $a[0] <=> $b[0]);

        $premier = [];
        foreach ($evenements as [, $sort, $entre]) {
            if ($sort) {
                $premier[spl_object_id($sort)] ??= 'sort';
            }
            if ($entre) {
                $premier[spl_object_id($entre)] ??= 'entre';
            }
        }
        $ouverts = [];
        foreach ($this->participations as $p) {
            if ($j = $p->getJoueur()) {
                $role = $premier[spl_object_id($j)] ?? null;
                $banc = 'non utilisé' === mb_strtolower(trim((string) $p->getPoste())) || $p->getNumero() > 11;
                if ('entre' !== $role && (!$banc || 'sort' === $role)) {
                    $ouverts[spl_object_id($j)] = 0.0;
                }
            }
        }

        $intervalles = [];
        foreach ($evenements as [$m, $sort, $entre]) {
            if ($sort && isset($ouverts[$s = spl_object_id($sort)])) {
                $intervalles[$s][] = [$ouverts[$s], $m];
                unset($ouverts[$s]);
            }
            if ($entre && !isset($ouverts[$e = spl_object_id($entre)])) {
                $ouverts[$e] = $m;
            }
        }
        foreach ($ouverts as $id => $debut) {
            $intervalles[$id][] = [$debut, \INF];
        }

        return $intervalles;
    }

    /** « 45+2 » → 45,02 : pour ranger les minutes, arrêts de jeu compris ; null si la minute est inconnue. */
    public static function ordreMinute(?string $temps): ?float
    {
        return preg_match('/^(\d+)(?:\+(\d+))?$/', (string) $temps, $m) ? (int) $m[1] + ((int) ($m[2] ?? 0)) / 100 : null;
    }

    /**
     * Buteur, passeur et joueur averti doivent être sur le terrain à la minute du but ou du carton.
     * Seuls les buts et cartons saisis ou modifiés sont contrôlés (voir But::$aControler) : les anciens
     * matchs aux remplacements incomplets restent enregistrables.
     */
    #[Assert\Callback]
    public function validerPresences(ExecutionContextInterface $context): void
    {
        $presences = $this->presences();
        $verifier = function (?Joueur $joueur, ?string $temps, string $chemin) use ($presences, $context): void {
            $m = self::ordreMinute($temps);
            if (!$joueur || null === $m) {
                return;
            }
            foreach ($presences[spl_object_id($joueur)] ?? [] as [$debut, $fin]) {
                if ($m >= $debut && $m <= $fin) {
                    return;
                }
            }
            $context->buildViolation('{{ joueur }} n\'est pas sur le terrain à la {{ minute }}e minute.')
                ->setParameter('{{ joueur }}', $joueur->getNomComplet())
                ->setParameter('{{ minute }}', (string) $temps)
                ->atPath($chemin)
                ->addViolation();
        };
        foreach ($this->buts as $i => $b) {
            if (!$b->isAdverse() && $b->isAControler()) {
                $verifier($b->getButeur(), $b->getTemps(), "buts[$i].buteur");
                $verifier($b->getPasseur(), $b->getTemps(), "buts[$i].passeur");
            }
        }
        foreach ($this->participations as $i => $p) {
            if ($p->getCarton() && $p->isAControler()) {
                $verifier($p->getJoueur(), $p->getMinuteCarton(), "participations[$i].minuteCarton");
            }
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
