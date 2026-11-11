<?php

namespace App\Service;

/**
 * Lit un calendrier copié depuis un site (Transfermarkt, FFF…) et en tire les matchs.
 *
 * Le texte est découpé en cellules (tabulations ou lignes) : chaque date ouvre un match,
 * les cellules suivantes donnent l'heure, les deux équipes puis le score s'il est joué.
 * L'équipe présente dans le plus de matchs est la nôtre.
 */
class ImportMatchs
{
    /** Début du nom du mois (sans accent) => numéro : « aoû », « aout », « août » ; « juin »/« jun » et « juil »/« jul ». */
    private const MOIS = [
        'jan' => 1, 'fe' => 2, 'mar' => 3, 'av' => 4, 'mai' => 5, 'juin' => 6, 'jun' => 6, 'juil' => 7, 'jul' => 7,
        'ao' => 8, 'se' => 9, 'oc' => 10, 'no' => 11, 'de' => 12,
    ];
    /** Compétition indiquée sur la ligne d'un match (FFF) => compétition du club. */
    /** Dans l'ordre : un match amical d'une « Journée 33 » reste amical ; une ligne avec « Journée » est sinon un championnat. */
    private const COMPETITIONS = [
        'amica' => 'Amical', 'coupe' => 'Coupe', 'gambardella' => 'Coupe', 'tournoi' => 'Tournoi', 'championnat' => 'Championnat',
        'regional' => 'Championnat', 'departemental' => 'Championnat', 'district' => 'Championnat', 'division' => 'Championnat', 'journee' => 'Championnat',
    ];
    /** Cellules qui ne sont pas des noms d'équipes : en-têtes, compétitions (« U16 RÉGIONAL 1 - U17 - U16 Journée 26 »), mentions diverses. */
    private const PAS_UNE_EQUIPE = '/^(journ[ée]e|date|heure|domicile|ext[ée]rieur|r[ée]sultat|score|spectateurs?|syst[èe]me.*|entra[îi]neur.*|inconnu|report[ée]|annul[ée]|exempt|j\s*\d+|\d+e? journ[ée]e)$|championnat|coupe|groupe|poule|phase|tour\b|journ[ée]e|r[ée]gional|d[ée]partemental|district|division/iu';

    /**
     * @return array{nous: string|null, matchs: list<array{date: \DateTimeImmutable, heure: string|null, domicile: string, exterieur: string, butsDomicile: int|null, butsExterieur: int|null, aDomicile: bool, competition: string|null, journee: int|null, adversaire: string}>}
     */
    public function analyser(string $texte): array
    {
        $cellules = preg_split('/\t|\r?\n|\s\|\s/u', $texte) ?: [];
        $bruts = [];
        $courant = null;
        $journee = null;
        foreach ($cellules as $cellule) {
            $cellule = trim(preg_replace('/\s+/u', ' ', $cellule) ?? '');
            if ('' === $cellule) {
                continue;
            }
            if ($date = $this->date($cellule)) {
                if ($courant) {
                    $bruts[] = $courant;
                }
                $courant = ['date' => $date, 'heure' => $this->heure($cellule), 'equipes' => [], 'score' => null, 'buts' => [], 'competition' => null, 'journee' => $journee];
                continue;
            }
            // « Journée 11 » : en titre au-dessus des matchs (page d'une poule) ou sur la ligne d'un match.
            if (preg_match('/journ[ée]e\s*(\d{1,2})\b/iu', $cellule, $j)) {
                $journee = (int) $j[1];
                if ($courant && !$courant['equipes']) {
                    $courant['journee'] = $journee;
                }
            }
            if (!$courant) {
                continue;
            }
            if (!$courant['equipes'] && null !== ($competition = $this->competition($cellule))) {
                $courant['competition'] ??= $competition;
            } elseif ($this->estUneHeure($cellule)) {
                // FFF : l'heure d'un match à venir s'affiche entre les deux équipes, à la place du score.
                $courant['heure'] ??= $this->heure($cellule);
            } elseif (\count($courant['equipes']) < 2 && $this->estUneEquipe($cellule)) {
                $equipe = $this->nettoyer($cellule);
                // FFF : chaque nom apparaît deux fois de suite (logo puis nom).
                if (end($courant['equipes']) !== $equipe) {
                    $courant['equipes'][] = $equipe;
                }
            } elseif ($courant['equipes'] && null === $courant['score'] && preg_match('/^(\d{1,2})\s*[:\-–]\s*(\d{1,2})(\s|$)/u', $cellule, $m)) {
                $courant['score'] = [(int) $m[1], (int) $m[2]];
            } elseif (1 === \count($courant['equipes']) && null === $courant['score'] && preg_match('/^\d{1,2}$/', $cellule)) {
                // Score sur plusieurs lignes (« 1 », « - », « 1 ») entre les deux équipes.
                $courant['buts'][] = (int) $cellule;
                if (2 === \count($courant['buts'])) {
                    $courant['score'] = $courant['buts'];
                }
            }
        }
        if ($courant) {
            $bruts[] = $courant;
        }
        $bruts = array_values(array_filter($bruts, fn (array $b) => 2 === \count($b['equipes'])));

        // Notre équipe : celle qui revient dans le plus de matchs.
        $frequences = [];
        foreach ($bruts as $b) {
            foreach (array_unique($b['equipes']) as $e) {
                $frequences[$e] = ($frequences[$e] ?? 0) + 1;
            }
        }
        arsort($frequences);
        $nous = array_key_first($frequences);

        $matchs = [];
        foreach ($bruts as $b) {
            [$domicile, $exterieur] = $b['equipes'];
            $aDomicile = $domicile === $nous;
            $matchs[] = [
                'date' => $b['date'],
                'heure' => $b['heure'],
                'domicile' => $domicile,
                'exterieur' => $exterieur,
                'butsDomicile' => $b['score'][0] ?? null,
                'butsExterieur' => $b['score'][1] ?? null,
                'aDomicile' => $aDomicile,
                'competition' => $b['competition'],
                'journee' => $b['journee'],
                'adversaire' => $this->sansCategorie($aDomicile ? $exterieur : $domicile),
            ];
        }
        usort($matchs, fn (array $a, array $b) => $a['date'] <=> $b['date']);

        return ['nous' => $nous, 'matchs' => $matchs];
    }

    /** « dim. 23 août 2026 », « 23/08/2026 », « Dimanche 23 août 2026 - 15h00 »… */
    private function date(string $cellule): ?\DateTimeImmutable
    {
        $texte = $this->sansAccents(mb_strtolower($cellule));
        if (preg_match('/\b(\d{1,2})(?:er)?\s+([a-z]+)\.?\s+(\d{4})\b/', $texte, $m) && null !== ($numero = $this->mois($m[2]))) {
            [$jour, $mois, $annee] = [(int) $m[1], $numero, (int) $m[3]];
        } elseif (preg_match('#\b(\d{1,2})[/.](\d{1,2})[/.](\d{4}|\d{2})\b#', $texte, $m)) {
            [$jour, $mois, $annee] = [(int) $m[1], (int) $m[2], (int) $m[3] < 100 ? 2000 + (int) $m[3] : (int) $m[3]];
        } else {
            return null;
        }

        return checkdate($mois, $jour, $annee) ? new \DateTimeImmutable(\sprintf('%04d-%02d-%02d', $annee, $mois, $jour)) : null;
    }

    private function mois(string $mot): ?int
    {
        foreach (self::MOIS as $debut => $numero) {
            if (str_starts_with($mot, $debut)) {
                return $numero;
            }
        }

        return null;
    }

    /** « MATCHES AMICAUX U19/U18 LFHF – Journée 33 » → « Amical » ; null si la cellule ne nomme pas de compétition. */
    private function competition(string $cellule): ?string
    {
        $texte = $this->sansAccents(mb_strtolower($cellule));
        foreach (self::COMPETITIONS as $mot => $competition) {
            if (str_contains($texte, $mot)) {
                return $competition;
            }
        }

        return null;
    }

    /** Heure au format du club (« 15h00 »), ou null si inconnue. */
    private function heure(string $cellule): ?string
    {
        return preg_match('/\b([01]?\d|2[0-3])\s*[:h]\s*([0-5]\d)\b/iu', $cellule, $m) ? \sprintf('%dh%s', (int) $m[1], $m[2]) : null;
    }

    /** Cellule qui n'est qu'une heure (« 15:00 », « 15h00 ») : jamais un score, les minutes ont deux chiffres et l'heure est d'au moins 8 h. */
    private function estUneHeure(string $cellule): bool
    {
        return (bool) preg_match('/^(0?[89]|1\d|2[0-3])\s*[:h]\s*[0-5]\d$/iu', $cellule);
    }

    private function estUneEquipe(string $cellule): bool
    {
        return preg_match('/\p{L}.*\p{L}/u', $cellule) && !preg_match(self::PAS_UNE_EQUIPE, $cellule) && null === $this->heure($cellule);
    }

    /** Sans le classement entre parenthèses (« Troyes U19 (1.) »), en majuscules. */
    private function nettoyer(string $equipe): string
    {
        return mb_strtoupper(trim(preg_replace('/\s*\(\d+\.?\)\s*$/u', '', $equipe) ?? $equipe));
    }

    /** Adversaire sans la catégorie ni le numéro d'équipe en fin de nom : « AJ AUXERRE U19 » ou « PARIS FC 2 » → nom seul. */
    public function sansCategorie(string $equipe): string
    {
        return trim(preg_replace('/(\s+(U\d{1,2}|NAT|N\d|\d))+$/u', '', $equipe) ?? $equipe) ?: $equipe;
    }

    private function sansAccents(string $texte): string
    {
        return strtr($texte, ['é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e', 'û' => 'u', 'ù' => 'u', 'ô' => 'o', 'î' => 'i', 'à' => 'a', 'â' => 'a', 'ç' => 'c']);
    }
}
