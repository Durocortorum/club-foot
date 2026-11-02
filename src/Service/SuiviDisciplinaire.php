<?php

namespace App\Service;

use App\Entity\Participation;

/**
 * Suivi des cartons (écran « Cartons » d'Access) : décompte par type et alertes de suspension.
 *
 * Règle appliquée, à titre indicatif : trois avertissements en moins de trois mois ou un carton
 * rouge entraînent un match de suspension, purgé dès que l'équipe a joué un match après le carton
 * (que le joueur y ait participé ou non). Les décisions officielles (commission de discipline,
 * date d'effet, nombre de matchs) restent à vérifier.
 */
class SuiviDisciplinaire
{
    public const PERIODE = '-3 months';

    /**
     * @param list<array{id: int, nom: string, prenom: string, carton: string, date: ?\DateTimeImmutable, rencontre: int}> $cartons
     *                                                                                                                              du plus récent au plus ancien
     * @param ?\DateTimeImmutable                                                                                        $dernierMatch date du dernier match de l'équipe dans la sélection
     *
     * @return array{reference: ?\DateTimeImmutable, parType: array<string, int>, recents: list<array<string, mixed>>}
     */
    public function analyser(array $cartons, ?\DateTimeImmutable $dernierMatch): array
    {
        $parType = array_fill_keys(array_keys(Participation::CARTONS), 0);
        foreach ($cartons as $c) {
            ++$parType[$c['carton']];
        }

        // Date de référence : le dernier match de la sélection, sans dépasser aujourd'hui.
        $reference = $cartons && $dernierMatch ? min($dernierMatch, new \DateTimeImmutable('today')) : null;
        if (!$reference) {
            return ['reference' => null, 'parType' => $parType, 'recents' => []];
        }

        $debut = $reference->modify(self::PERIODE);
        $joueurs = [];
        foreach (array_reverse($cartons) as $c) {
            if (!$c['date'] || $c['date'] < $debut || $c['date'] > $reference || 'BL' === $c['carton']) {
                continue;
            }
            $j = &$joueurs[$c['id']];
            $j ??= ['id' => $c['id'], 'nom' => $c['nom'], 'prenom' => $c['prenom'], 'avertissements' => [], 'rouges' => [], 'purges' => []];
            if (\in_array($c['carton'], Participation::CARTONS_ROUGES, true)) {
                $j['rouges'][] = $c['date'];
            }
            if (\in_array($c['carton'], Participation::CARTONS_JAUNES, true)) {
                $j['avertissements'][] = $c['date'];
            }
            unset($j);
        }

        // L'équipe a-t-elle joué après cette date ? Alors le match de suspension est purgé.
        $rejoue = fn (\DateTimeImmutable $date) => $date < $reference;

        $recents = [];
        foreach ($joueurs as $j) {

            $j['suspension'] = null;
            foreach ($j['rouges'] as $date) {
                if ($rejoue($date)) {
                    $j['purges'][] = 'rouge du ' . $date->format('d/m');
                } else {
                    $j['suspension'] = 'carton rouge le ' . $date->format('d/m');
                }
            }
            // Avertissements : chaque série de trois entraîne une suspension.
            $enCours = [];
            foreach ($j['avertissements'] as $date) {
                $enCours[] = $date;
                if (3 === \count($enCours)) {
                    if ($rejoue($date)) {
                        $j['purges'][] = '3 avertissements au ' . $date->format('d/m');
                    } else {
                        $j['suspension'] ??= '3e avertissement le ' . $date->format('d/m');
                    }
                    $enCours = [];
                }
            }
            $j['enCours'] = $enCours;
            $j['statut'] = match (true) {
                null !== $j['suspension'] => 'suspendu',
                2 === \count($enCours) => 'attention',
                1 === \count($enCours) => 'surveille',
                default => 'purge',
            };
            $recents[] = $j;
        }
        usort($recents, fn (array $a, array $b) => [\count($b['enCours']), $a['nom']] <=> [\count($a['enCours']), $b['nom']]);

        return ['reference' => $reference, 'parType' => $parType, 'recents' => $recents];
    }
}
