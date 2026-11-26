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

    /** Système habituel de l'adversaire (Rencontre::SYSTEMES), dessiné sur le petit terrain de l'analyse. */
    #[ORM\Column(length: 20, nullable: true)]
    private ?string $systemeHabituel = null;

    /** Animation de jeu : pressing, relance, coups de pied arrêtés. */
    public const JEU = [
        'pressing' => ['Pressing', '🏃', 'Ex. : pressing haut et intense les 20 premières minutes'],
        'relance' => ['Relance', '⚽', 'Ex. : relance courte, privilégie les côtés'],
        'cpa' => ['Coups de pied arrêtés', '🚩', 'Ex. : dangereux sur corners (jeu au premier poteau)'],
    ];

    /**
     * Pour chaque thème de JEU : texte et niveau de danger de 0 (non noté) à 5.
     *
     * @var array<string, array{texte?: string, niveau?: int}>|null
     */
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $jeu = null;

    /**
     * Joueurs adverses à surveiller (numéro, poste, nom, ce qu'il faut savoir), dans l'ordre noté.
     *
     * @var list<array{numero?: int, poste?: string, nom?: string, note?: string}>|null
     */
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $joueursDangereux = null;

    /**
     * Vidéos utiles en plus de celles des matchs (extraits, match contre un autre club) : titre et lien.
     *
     * @var list<array{titre: string, url: string}>|null
     */
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $videos = null;

    /**
     * Effectif de l'adversaire : numéro de maillot, nom s'il est connu, ligne (G, DEF, MIL, AT).
     *
     * @var list<array{numero?: int, nom?: string, poste?: string}>|null
     */
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $effectif = null;

    public const LIGNES_EFFECTIF = ['G' => 'Gardiens', 'DEF' => 'Défenseurs', 'MIL' => 'Milieux', 'AT' => 'Attaquants'];
    public const MAX_EFFECTIF = 30;
    public const MAX_JOUEURS = 6;
    public const MAX_VIDEOS = 4;

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

    public function getSystemeHabituel(): ?string
    {
        return $this->systemeHabituel;
    }

    public function setSystemeHabituel(?string $systeme): static
    {
        $this->systemeHabituel = \in_array($systeme, array_merge(...array_values(Rencontre::SYSTEMES)), true) ? $systeme : null;

        return $this;
    }

    /** @return array{texte: ?string, niveau: int} */
    public function getTheme(string $theme): array
    {
        return ['texte' => $this->jeu[$theme]['texte'] ?? null, 'niveau' => (int) ($this->jeu[$theme]['niveau'] ?? 0)];
    }

    public function setTheme(string $theme, ?string $texte, int $niveau): static
    {
        if (!isset(self::JEU[$theme])) {
            return $this;
        }
        $propre = array_filter(['texte' => self::propre($texte), 'niveau' => max(0, min(5, $niveau)) ?: null], fn ($v) => null !== $v);
        $jeu = $this->jeu ?? [];
        if ($propre) {
            $jeu[$theme] = $propre;
        } else {
            unset($jeu[$theme]);
        }
        $this->jeu = $jeu ?: null;

        return $this;
    }

    public function aDuJeu(): bool
    {
        return (bool) $this->jeu;
    }

    /** @return list<array{numero?: int, poste?: string, nom?: string, note?: string}> */
    public function getJoueursDangereux(): array
    {
        return $this->joueursDangereux ?? [];
    }

    /** Lignes vides écartées, textes raccourcis, au plus MAX_JOUEURS. */
    public function setJoueursDangereux(array $joueurs): static
    {
        $liste = [];
        foreach ($joueurs as $j) {
            $numero = is_numeric($j['numero'] ?? null) ? max(1, min(99, (int) $j['numero'])) : null;
            $ligne = array_filter([
                'numero' => $numero,
                'poste' => mb_substr(trim((string) ($j['poste'] ?? '')), 0, 30) ?: null,
                'nom' => mb_substr(trim((string) ($j['nom'] ?? '')), 0, 60) ?: null,
                'note' => mb_substr(trim((string) ($j['note'] ?? '')), 0, 200) ?: null,
            ], fn ($v) => null !== $v);
            if ($ligne && \count($liste) < self::MAX_JOUEURS) {
                $liste[] = $ligne;
            }
        }
        $this->joueursDangereux = $liste ?: null;

        return $this;
    }

    /** @return list<array{numero?: int, nom?: string, poste?: string}> dans l'ordre noté */
    public function getEffectif(): array
    {
        return $this->effectif ?? [];
    }

    /**
     * Effectif rangé par ligne (gardiens, défenseurs, milieux, attaquants, puis sans poste), par numéro dans chaque ligne.
     *
     * @return array<string, list<array{numero?: int, nom?: string, poste?: string}>> libellé de la ligne => joueurs
     */
    public function getEffectifParLigne(): array
    {
        $lignes = [];
        foreach (self::LIGNES_EFFECTIF + ['' => 'Sans poste'] as $code => $libelle) {
            $joueurs = array_values(array_filter($this->getEffectif(), fn (array $j) => ($j['poste'] ?? '') === $code));
            usort($joueurs, fn ($a, $b) => [$a['numero'] ?? 100, $a['nom'] ?? ''] <=> [$b['numero'] ?? 100, $b['nom'] ?? '']);
            if ($joueurs) {
                $lignes[$libelle] = $joueurs;
            }
        }

        return $lignes;
    }

    /** Lignes sans numéro ni nom écartées, au plus MAX_EFFECTIF. */
    public function setEffectif(array $joueurs): static
    {
        $liste = [];
        foreach ($joueurs as $j) {
            $ligne = array_filter([
                'numero' => is_numeric($j['numero'] ?? null) ? max(1, min(99, (int) $j['numero'])) : null,
                'nom' => mb_substr(trim((string) ($j['nom'] ?? '')), 0, 60) ?: null,
            ], fn ($v) => null !== $v);
            if ($ligne && \count($liste) < self::MAX_EFFECTIF) {
                $poste = (string) ($j['poste'] ?? '');
                $liste[] = $ligne + (isset(self::LIGNES_EFFECTIF[$poste]) ? ['poste' => $poste] : []);
            }
        }
        $this->effectif = $liste ?: null;

        return $this;
    }

    /** @return list<array{titre: string, url: string}> */
    public function getVideos(): array
    {
        return $this->videos ?? [];
    }

    /** Liens complets seulement (https://… ajouté s'il manque), au plus MAX_VIDEOS. */
    public function setVideos(array $videos): static
    {
        $liste = [];
        foreach ($videos as $v) {
            $url = trim((string) ($v['url'] ?? ''));
            if ('' === $url) {
                continue;
            }
            if (!preg_match('#^https?://#i', $url)) {
                $url = 'https://' . $url;
            }
            if (filter_var($url, \FILTER_VALIDATE_URL) && \count($liste) < self::MAX_VIDEOS) {
                $liste[] = ['titre' => mb_substr(trim((string) ($v['titre'] ?? '')), 0, 80) ?: 'Vidéo', 'url' => mb_substr($url, 0, 255)];
            }
        }
        $this->videos = $liste ?: null;

        return $this;
    }

    /**
     * Places des joueurs d'un système sur un demi-terrain vertical (en %, gardien en bas) : « 4 3 3 » → gardien, puis
     * 4, 3 et 3 joueurs répartis en largeur, ligne par ligne vers l'attaque ; « 4 4 2 losange » : milieu en losange.
     *
     * @return list<array{x: float, y: float}>
     */
    public static function positionsSysteme(?string $systeme): array
    {
        if (!$systeme || !preg_match_all('/\d/', $systeme, $m)) {
            return [];
        }
        $lignes = array_map('intval', $m[0]);
        if (str_contains($systeme, 'losange') && 4 === ($lignes[1] ?? null)) {
            array_splice($lignes, 1, 1, [1, 2, 1]);
        }
        $places = [['x' => 50.0, 'y' => 92.0]];
        $n = \count($lignes);
        foreach ($lignes as $i => $nb) {
            $y = 76 - $i * (64 / max(1, $n - 1 ?: 1));
            for ($k = 0; $k < $nb; ++$k) {
                $places[] = ['x' => round((($k + 1) / ($nb + 1)) * 100, 1), 'y' => round($n > 1 ? $y : 50, 1)];
            }
        }

        return $places;
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
