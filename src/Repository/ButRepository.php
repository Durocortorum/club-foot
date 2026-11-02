<?php

namespace App\Repository;

use App\Entity\But;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<But>
 */
class ButRepository extends ServiceEntityRepository
{
    /** Tranches de 15 minutes ; au-delà de la 90e : arrêt de jeu. */
    public const TRANCHES = [
        '1-15' => '1re – 15e min',
        '16-30' => '16e – 30e min',
        '31-45' => '31e – 45e min',
        '46-60' => '46e – 60e min',
        '61-75' => '61e – 75e min',
        '76-90' => '76e – 90e min',
        'arret' => 'Arrêt de jeu',
        'inconnue' => 'Minute non notée',
    ];

    public function __construct(ManagerRegistry $registry, private readonly RencontreRepository $rencontres)
    {
        parent::__construct($registry, But::class);
    }

    /**
     * Buts pour et contre le Stade de Reims, additionnés par quart d'heure et par phase de jeu,
     * pour les mêmes filtres que la liste des matchs.
     *
     * @param list<string>|null $secteurs
     *
     * @return array{matchs: int, pour: array<string, mixed>, contre: array<string, mixed>, max: int}
     */
    public function analyse(?array $secteurs, ?string $saison, ?int $categorieId): array
    {
        $qb = $this->createQueryBuilder('b')
            ->select('b.adverse, b.minute, b.phase, b.conditions')
            ->join('b.rencontre', 'r');
        $buts = $this->rencontres->filtrer($qb, $secteurs, $saison, $categorieId)->getQuery()->getArrayResult();

        $matchs = (int) $this->rencontres->filtrer($this->rencontres->createQueryBuilder('r')->select('COUNT(r.id)'), $secteurs, $saison, $categorieId)
            ->getQuery()->getSingleScalarResult();

        $vide = fn () => [
            'total' => 0,
            'tranches' => array_fill_keys(array_keys(self::TRANCHES), 0),
            'phases' => array_fill_keys([...array_keys(But::PHASES), 'inconnue'], 0),
        ];
        $resultat = ['pour' => $vide(), 'contre' => $vide()];

        foreach ($buts as $b) {
            $cote = $b['adverse'] ? 'contre' : 'pour';
            ++$resultat[$cote]['total'];
            ++$resultat[$cote]['tranches'][self::tranche($b['minute'])];
            ++$resultat[$cote]['phases'][isset(But::PHASES[$b['phase']]) ? $b['phase'] : 'inconnue'];
            $situation = $b['conditions'] ?? 'Non renseignée';
            $situations[$situation][$cote] = ($situations[$situation][$cote] ?? 0) + 1;
        }

        // Situations des buts, de la plus fréquente à la plus rare (pour et contre confondus).
        $situations ??= [];
        foreach ($situations as &$s) {
            $s += ['pour' => 0, 'contre' => 0];
        }
        unset($s);
        uksort($situations, fn ($a, $b) => [$situations[$b]['pour'] + $situations[$b]['contre'], $a] <=> [$situations[$a]['pour'] + $situations[$a]['contre'], $b]);
        // « Non renseignée » toujours en dernier.
        if (null !== $nonRenseignee = $situations['Non renseignée'] ?? null) {
            unset($situations['Non renseignée']);
            $situations['Non renseignée'] = $nonRenseignee;
        }
        $maxSituations = 1;
        foreach ($situations as $s) {
            $maxSituations = max($maxSituations, $s['pour'], $s['contre']);
        }
        foreach ($resultat as &$cote) {
            $cote['moyenne'] = $matchs ? $cote['total'] / $matchs : null;
        }
        unset($cote);

        return $resultat + [
            'matchs' => $matchs,
            'max' => max(1, ...array_values($resultat['pour']['tranches']), ...array_values($resultat['contre']['tranches'])),
            'situations' => $situations,
            'maxSituations' => $maxSituations,
        ];
    }

    public static function tranche(?int $minute): string
    {
        return match (true) {
            null === $minute => 'inconnue',
            $minute <= 15 => '1-15',
            $minute <= 30 => '16-30',
            $minute <= 45 => '31-45',
            $minute <= 60 => '46-60',
            $minute <= 75 => '61-75',
            $minute <= 90 => '76-90',
            default => 'arret',
        };
    }
}
