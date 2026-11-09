<?php

namespace App\Service;

/**
 * Lit un classement copié depuis la FFF ou Transfermarkt.
 *
 * FFF : rang, progression éventuelle (« 2 » après une flèche), équipe (parfois en double avec le logo),
 * puis Pts, J., G., N., P., F., P/Bo., Bp., Bc., Diff. ; la série en cours (pastilles) ne se copie pas.
 * Transfermarkt : rang, club, matchs, G, N, P, buts « 25:11 », différence, points.
 *
 * Le texte est découpé en cellules ; chaque nom d'équipe est suivi de ses nombres, et les nombres
 * en trop à la fin d'une équipe sont le rang (et la progression) de la suivante.
 */
class ImportClassement
{
    /** En-têtes, mentions et lignes de la page qui ne sont pas des équipes. */
    private const PAS_UNE_EQUIPE = '/^(pr\.?|equipe|équipe|club|pts|points|j\.?|g\.?|n\.?|p\.?|f\.?|p\/bo\.?|bp\.?|bc\.?|diff\.?|\+\/-|buts|matchs|série en cours|serie en cours|classement|#|pl\.?|rang)$|sous réserve|procédures|journée|groupe|poule|championnat|national|régional|regional/iu';

    /**
     * @return list<array{rang: int, equipe: string, points: int, joues: int, gagnes: int, nuls: int, perdus: int, pour: int, contre: int, diff: int}>
     */
    public function analyser(string $texte): array
    {
        $cellules = [];
        foreach (preg_split('/\t|\r?\n/u', $texte) ?: [] as $c) {
            $c = trim(preg_replace('/\s+/u', ' ', $c) ?? '');
            if ('' !== $c) {
                $cellules[] = $c;
            }
        }

        // Suite d'équipes, chacune avec les nombres qui la précèdent et ceux qui la suivent.
        $avant = [];
        $equipes = [];
        foreach ($cellules as $c) {
            if (preg_match('/^[-−–]?\d+$/u', $c) || preg_match('/^\d+\s*:\s*\d+$/', $c)) {
                $nombre = preg_match('/^(\d+)\s*:\s*(\d+)$/', $c, $m) ? [(int) $m[1], (int) $m[2]] : (int) str_replace(['−', '–'], '-', $c);
                $equipes ? $equipes[\count($equipes) - 1]['apres'][] = $nombre : $avant[] = $nombre;
            } elseif ($this->estUneEquipe($c)) {
                $nom = mb_strtoupper($c);
                if ($equipes && end($equipes)['nom'] === $nom && !end($equipes)['apres']) {
                    continue; // FFF : nom répété (logo puis nom).
                }
                $equipes[] = ['nom' => $nom, 'apres' => []];
            }
        }
        // Textes de la page sans nombres derrière (menus, titres, mentions) : ce ne sont pas des équipes.
        $premiere = array_key_first(array_filter($equipes, fn (array $e) => \count($e['apres']) >= 7));
        if (null !== $premiere && $premiere > 0) {
            // Les nombres juste avant la première équipe (rang, progression) sont dans la « suite » du texte précédent.
            $avant = $equipes[$premiere - 1]['apres'] ?: $avant;
        }
        $equipes = array_values(array_filter($equipes, fn (array $e) => \count($e['apres']) >= 7));
        if (\count($equipes) < 2) {
            return [];
        }

        $lignes = [];
        // Rang de la première équipe : le nombre juste avant elle, ou l'avant-dernier s'il est suivi d'une progression.
        $avant = array_values(array_filter($avant, fn ($n) => \is_int($n) && $n >= 1 && $n <= \count($equipes)));
        $rang = \count($avant) >= 2 ? $avant[\count($avant) - 2] : ($avant[0] ?? 1);
        foreach ($equipes as $e) {
            $stats = $e['apres'];
            $ligne = \is_array($stats[4] ?? null) ? $this->transfermarkt($stats) : $this->fff($stats);
            if (!$ligne) {
                return [];
            }
            $lignes[] = ['rang' => \is_int($rang) ? $rang : \count($lignes) + 1, 'equipe' => $e['nom']] + $ligne['ligne'];
            // Nombres restants : rang (et progression) de l'équipe suivante.
            $rang = $ligne['reste'][0] ?? \count($lignes) + 1;
        }

        return $this->coherent($lignes) ? $lignes : [];
    }

    /** FFF : Pts, J., G., N., P., F., P/Bo., Bp., Bc., Diff. */
    private function fff(array $n): ?array
    {
        if (\count($n) < 10 || array_filter(\array_slice($n, 0, 10), 'is_array')) {
            return null;
        }

        return [
            'ligne' => ['points' => $n[0], 'joues' => $n[1], 'gagnes' => $n[2], 'nuls' => $n[3], 'perdus' => $n[4], 'pour' => $n[7], 'contre' => $n[8], 'diff' => $n[9]],
            'reste' => \array_slice($n, 10),
        ];
    }

    /** Transfermarkt : matchs, G, N, P, « Bp:Bc », différence, points. */
    private function transfermarkt(array $n): ?array
    {
        if (\count($n) < 7 || !\is_array($n[4])) {
            return null;
        }

        return [
            'ligne' => ['points' => $n[6], 'joues' => $n[0], 'gagnes' => $n[1], 'nuls' => $n[2], 'perdus' => $n[3], 'pour' => $n[4][0], 'contre' => $n[4][1], 'diff' => $n[5]],
            'reste' => \array_slice($n, 7),
        ];
    }

    /** Chaque ligne doit tenir debout : matchs = G + N + P (+ forfaits), différence = Bp − Bc. */
    private function coherent(array $lignes): bool
    {
        foreach ($lignes as $l) {
            if ($l['gagnes'] + $l['nuls'] + $l['perdus'] > $l['joues'] || $l['pour'] - $l['contre'] !== $l['diff']) {
                return false;
            }
        }

        return true;
    }

    private function estUneEquipe(string $cellule): bool
    {
        return (bool) preg_match('/\p{L}.*\p{L}/u', $cellule) && !preg_match(self::PAS_UNE_EQUIPE, $cellule) && mb_strlen($cellule) <= 60;
    }
}
