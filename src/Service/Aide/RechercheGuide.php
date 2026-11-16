<?php

namespace App\Service\Aide;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Recherche dans le guide de l'application (templates/aide/guide.md), sans service extérieur ni intelligence
 * artificielle : le guide est découpé en passages (une rubrique « ## », ou un point de liste avec ses étapes) ;
 * la question est réduite à ses mots utiles, rapprochés par leur début (« convoquer » et « convocation »)
 * et par quelques synonymes ; les passages qui partagent le plus de mots rares l'emportent.
 */
class RechercheGuide
{
    /** Mots sans intérêt pour la recherche. */
    private const MOTS_VIDES = ['comment', 'est', 'ce', 'que', 'qu', 'quoi', 'quel', 'quelle', 'quels', 'quelles', 'ou', 'je', 'j', 'on', 'il', 'elle', 'nous', 'vous',
        'le', 'la', 'les', 'l', 'un', 'une', 'des', 'de', 'du', 'd', 'a', 'au', 'aux', 'en', 'et', 'pour', 'par', 'dans', 'sur', 'avec', 'sans', 'mon', 'ma', 'mes',
        'ton', 'ta', 'tes', 'son', 'sa', 'ses', 'notre', 'nos', 'votre', 'vos', 'leur', 'leurs', 'ce', 'cet', 'cette', 'ces', 'faire', 'fait', 'peut', 'peux',
        'puis', 'pouvoir', 'veux', 'voudrais', 'faut', 'doit', 'dois', 'trouve', 'trouver', 'voir', 'mettre', 'met', 'ajouter', 'avoir', 'ai', 'y', 'ne', 'pas',
        'plus', 'tout', 'tous', 'si', 'se', 's', 'me', 'm', 'te', 't', 'lui', 'bonjour', 'merci', 'svp', 'stp', 'application', 'appli', 'site', 'club', 'aide', 'aider',
        'creer', 'cree', 'nouveau', 'nouvel', 'nouvelle', 'nouveaux', 'nouvelles'];
    /** Synonymes : début de mot de la question => débuts de mots du guide équivalents. */
    private const SYNONYMES = [
        'payer' => ['paiem', 'cotis', 'regle'], 'paye' => ['paiem', 'cotis'], 'regle' => ['paiem'], 'argen' => ['finan', 'paiem'], 'compt' => ['finan', 'compt'],
        'absen' => ['absen', 'prese'], 'manqu' => ['absen'], 'malad' => ['absen', 'bless'], 'bless' => ['infir', 'bless'], 'soign' => ['infir'],
        'notif' => ['notif', 'telep'], 'alert' => ['notif'], 'porta' => ['telep', 'notif'], 'telep' => ['telep', 'notif'], 'iphon' => ['iphon', 'insta'],
        'parti' => ['match', 'convo'], 'renco' => ['match'], 'equip' => ['categ', 'equip'], 'categ' => ['categ'], 'enfan' => ['famil', 'joueu'], 'fils' => ['famil', 'joueu'],
        'fille' => ['famil', 'joueu'], 'paren' => ['famil', 'paren'], 'inscr' => ['invit', 'creer', 'inscr'], 'excel' => ['excel', 'impor'], 'fichi' => ['impor', 'fichi'],
        'liste' => ['listi', 'impor'], 'stati' => ['stati'], 'stats' => ['stati'], 'table' => ['table', 'direc'], 'direc' => ['direc'], 'chron' => ['direc', 'chron'],
        'mot' => ['passe'], 'passw' => ['passe'], 'oubli' => ['oubli'], 'conne' => ['conne', 'compt'], 'entra' => ['entra', 'plann'], 'seanc' => ['seanc', 'entra'],
        'droit' => ['droit', 'utili'], 'acces' => ['droit', 'acces'], 'logo' => ['logo', 'appar'], 'coule' => ['coule', 'appar'], 'maill' => ['bouti', 'numer'], 'vendr' => ['bouti', 'vendr'],
        'vente' => ['bouti'], 'achet' => ['bouti', 'comma'], 'comma' => ['comma', 'bouti'], 'score' => ['score', 'feuil'], 'resul' => ['score', 'feuil'],
        'trans' => ['trans', 'depla'], 'voitu' => ['trans'], 'car' => ['trans'], 'bus' => ['trans', 'minib'], 'amend' => ['amend', 'disci'], 'sanct' => ['disci'],
        'ecole' => ['scola'], 'bulle' => ['scola'], 'messa' => ['messa'], 'ecrir' => ['messa'], 'envoy' => ['envoy', 'messa'], 'tache' => ['tache'],
    ];
    private const POIDS_TITRE = 2.0;
    private const BONUS_TITRE = 1.5;
    private const K1 = 1.2;
    private const B = 0.75;

    /** @var list<array{rubrique: string, texte: string, mots: array<string, float>, longueur: int}>|null */
    private ?array $passages = null;
    private float $longueurMoyenne = 1.0;
    /** @var array<string, float> rareté de chaque mot dans le guide */
    private array $rarete = [];

    public function __construct(
        #[Autowire('%kernel.project_dir%/templates/aide/guide.md')]
        private readonly string $cheminGuide,
    ) {
    }

    /**
     * Les passages du guide qui répondent le mieux à la question (un ou deux), ou une liste vide.
     *
     * @param bool $famille compte famille : les rubriques de l'espace famille passent devant
     *
     * @return list<array{rubrique: string, texte: string}>
     */
    public function chercher(string $question, bool $famille = false): array
    {
        $this->charger();
        $termes = [];
        foreach (self::mots($question) as $mot) {
            $termes[$mot] = true;
            foreach (self::SYNONYMES[$mot] ?? [] as $s) {
                $termes[$s] ??= false; // synonyme : compté un peu moins
            }
        }
        if (!$termes) {
            return [];
        }
        // Note de type BM25 : mots rares qui reviennent dans le passage, sans avantager les longs passages.
        $notes = [];
        foreach ($this->passages as $i => $p) {
            $note = 0.0;
            $normalisation = self::K1 * (1 - self::B + self::B * $p['longueur'] / $this->longueurMoyenne);
            foreach ($termes as $terme => $direct) {
                if (isset($p['mots'][$terme])) {
                    $tf = $p['mots'][$terme];
                    $note += ($this->rarete[$terme] ?? 1.0) * $tf * (self::K1 + 1) / ($tf + $normalisation) * ($direct ? 1.0 : 0.6);
                }
                // Mot du titre (rubrique, ou libellé en gras en tête du passage) : le sujet même du passage.
                if (isset($p['titre'][$terme])) {
                    $note += ($this->rarete[$terme] ?? 1.0) * self::BONUS_TITRE * ($direct ? 1.0 : 0.6);
                }
            }
            if ($note > 0) {
                // Une famille cherche d'abord dans son espace ; l'encadrement, dans le reste.
                $familial = str_contains(self::sansAccents(mb_strtolower($p['rubrique'])), 'famille');
                $notes[$i] = $note * ($famille === $familial ? 1.5 : 1.0);
            }
        }
        if (!$notes) {
            return [];
        }
        arsort($notes);
        $meilleurs = array_slice($notes, 0, 2, true);
        $premier = reset($meilleurs);
        $resultat = [];
        foreach ($meilleurs as $i => $note) {
            // Le second passage seulement s'il est presque aussi pertinent.
            if ($note === $premier || $note >= 0.75 * $premier) {
                $resultat[] = ['rubrique' => $this->passages[$i]['rubrique'], 'texte' => $this->passages[$i]['texte']];
            }
        }

        return $resultat;
    }

    /** @return list<string> titres des rubriques du guide (pour proposer d'autres sujets) */
    public function rubriques(): array
    {
        $this->charger();

        return array_values(array_unique(array_column($this->passages, 'rubrique')));
    }

    /**
     * Découpe : chaque rubrique « ## » donne son paragraphe d'introduction, puis un passage par point de liste
     * de premier niveau (avec ses sous-points et ses étapes numérotées) ; une rubrique sans liste reste entière.
     */
    private function charger(): void
    {
        if (null !== $this->passages) {
            return;
        }
        $this->passages = [];
        $rubrique = null;
        $bloc = [];
        $fermer = function () use (&$rubrique, &$bloc): void {
            $texte = trim(implode("\n", $bloc));
            if ($rubrique && '' !== $texte) {
                $mots = [];
                foreach (self::mots($rubrique) as $m) {
                    $mots[$m] = ($mots[$m] ?? 0) + self::POIDS_TITRE;
                }
                $duTexte = self::mots($texte);
                foreach ($duTexte as $m) {
                    $mots[$m] = ($mots[$m] ?? 0) + 1.0;
                }
                // Titre : la rubrique et le libellé en gras qui ouvre le point de liste (« - **Importer des joueurs** »).
                $libelle = preg_match('/^- \*\*(.+?)\*\*/', $texte, $m) ? $m[1] : '';
                $titre = array_fill_keys(self::mots($rubrique . ' ' . $libelle), true);
                $this->passages[] = ['rubrique' => $rubrique, 'texte' => $texte, 'mots' => $mots, 'titre' => $titre, 'longueur' => max(1, \count($duTexte))];
            }
            $bloc = [];
        };
        foreach (preg_split('/\R/', (string) file_get_contents($this->cheminGuide)) as $ligne) {
            if (str_starts_with($ligne, '## ')) {
                $fermer();
                $rubrique = trim(substr($ligne, 3));
            } elseif (str_starts_with($ligne, '# ')) {
                continue;
            } elseif (str_starts_with($ligne, '- ') && $bloc) {
                // Nouveau point de liste de premier niveau : le bloc précédent devient un passage (les étapes
                // numérotées d'une rubrique restent ensemble).
                $fermer();
                $bloc[] = $ligne;
            } else {
                $bloc[] = $ligne;
            }
        }
        $fermer();
        // Rareté : un mot présent dans peu de passages pèse plus lourd.
        $presence = [];
        foreach ($this->passages as $p) {
            foreach (array_keys($p['mots']) as $m) {
                $presence[$m] = ($presence[$m] ?? 0) + 1;
            }
        }
        $n = \count($this->passages);
        foreach ($presence as $m => $k) {
            $this->rarete[$m] = log(1 + ($n - $k + 0.5) / ($k + 0.5));
        }
        $this->longueurMoyenne = max(1.0, array_sum(array_column($this->passages, 'longueur')) / max(1, $n));
    }

    /** @return list<string> mots utiles réduits à leurs 5 premières lettres, sans accents */
    private static function mots(string $texte): array
    {
        $texte = self::sansAccents(mb_strtolower(strip_tags($texte)));
        $mots = [];
        foreach (preg_split('/[^a-z0-9]+/', $texte, -1, \PREG_SPLIT_NO_EMPTY) as $mot) {
            if (\in_array($mot, self::MOTS_VIDES, true) || (mb_strlen($mot) < 3 && !ctype_digit($mot))) {
                continue;
            }
            $mots[] = substr($mot, 0, 5);
        }

        return $mots;
    }

    private static function sansAccents(string $texte): string
    {
        return strtr($texte, ['à' => 'a', 'â' => 'a', 'ä' => 'a', 'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e', 'î' => 'i', 'ï' => 'i',
            'ô' => 'o', 'ö' => 'o', 'ù' => 'u', 'û' => 'u', 'ü' => 'u', 'ç' => 'c', 'É' => 'e', 'œ' => 'oe']);
    }
}
