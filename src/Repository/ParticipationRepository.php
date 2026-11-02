<?php

namespace App\Repository;

use App\Entity\But;
use App\Entity\Joueur;
use App\Entity\Participation;
use App\Entity\Remplacement;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Participation>
 */
class ParticipationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry, private readonly RencontreRepository $rencontres)
    {
        parent::__construct($registry, Participation::class);
    }

    /**
     * Statistiques cumulées par joueur (écrans « statistiques » d'Access) : convocations,
     * matchs joués, titularisations, remplaçant au départ ou non utilisé, matchs joués
     * entièrement, minutes et part du temps de jeu, entrées et sorties en cours de match,
     * note moyenne, cartons, buts et passes décisives.
     *
     * La durée d'un match est le plus grand temps de jeu noté sur sa feuille.
     *
     * @param list<string>|null $secteurs
     *
     * @return array{joueurs: list<array<string, mixed>>, matchs: int, convoques: int, utilises: int, dureeReference: int, minutesPossibles: int}
     */
    public function statistiques(?array $secteurs, ?string $saison, ?int $categorieId): array
    {
        $qb = $this->createQueryBuilder('p')
            ->select('IDENTITY(p.rencontre) AS rencontre, p.tempsJeu, p.numero, p.poste, p.note, p.carton')
            ->addSelect('j.id, j.nom, j.prenom, j.poste AS posteHabituel, cj.nom AS categorie')
            ->join('p.rencontre', 'r')
            ->join('p.joueur', 'j')
            ->leftJoin('j.categorie', 'cj');
        $lignes = $this->rencontres->filtrer($qb, $secteurs, $saison, $categorieId)->getQuery()->getArrayResult();

        $durees = [];
        foreach ($lignes as $l) {
            $durees[$l['rencontre']] = max($durees[$l['rencontre']] ?? 0, (int) $l['tempsJeu']);
        }

        $vide = [
            'convocations' => 0, 'matchs' => 0, 'titularisations' => 0, 'remplacantDepart' => 0, 'nonUtilise' => 0,
            'complets' => 0, 'minutes' => 0, 'notes' => [], 'jaunes' => 0, 'rouges' => 0, 'exclusions' => 0,
        ];
        $joueurs = [];
        foreach ($lignes as $l) {
            $j = &$joueurs[$l['id']];
            $j ??= ['id' => $l['id'], 'nom' => $l['nom'], 'prenom' => $l['prenom'], 'posteHabituel' => $l['posteHabituel'], 'categorie' => $l['categorie']] + $vide;
            $temps = (int) $l['tempsJeu'];
            $titulaire = null !== $l['numero'] && $l['numero'] >= 1 && $l['numero'] <= 11 && 'Non utilisé' !== $l['poste'];

            ++$j['convocations'];
            $j['matchs'] += $temps > 0 ? 1 : 0;
            $j['titularisations'] += $titulaire ? 1 : 0;
            $j['remplacantDepart'] += !$titulaire && (null !== $l['numero'] || 'Non utilisé' === $l['poste']) ? 1 : 0;
            $j['nonUtilise'] += !$titulaire && 0 === $temps ? 1 : 0;
            $j['complets'] += $temps > 0 && $temps >= $durees[$l['rencontre']] ? 1 : 0;
            $j['minutes'] += $temps;
            $j['jaunes'] += \in_array($l['carton'], Participation::CARTONS_JAUNES, true) ? 1 : 0;
            $j['rouges'] += \in_array($l['carton'], Participation::CARTONS_ROUGES, true) ? 1 : 0;
            $j['exclusions'] += 'BL' === $l['carton'] ? 1 : 0;
            if (null !== $l['note']) {
                $j['notes'][] = $l['note'];
            }
        }
        unset($j);

        $buts = $this->compterButs('buteur', $secteurs, $saison, $categorieId);
        $passes = $this->compterButs('passeur', $secteurs, $saison, $categorieId);
        $entrees = $this->compterRemplacements('entrant', $secteurs, $saison, $categorieId);
        $sorties = $this->compterRemplacements('sortant', $secteurs, $saison, $categorieId);
        $matchs = \count($durees);
        // Durée de référence : la durée la plus fréquente des matchs de la sélection
        // (90 minutes en seniors, moins chez les jeunes), comme le calcul « 90 min × matchs » d'Access.
        $frequences = array_count_values(array_filter($durees));
        arsort($frequences);
        $dureeReference = (int) (array_key_first($frequences) ?? 0);
        $minutesPossibles = $matchs * $dureeReference;

        foreach ($joueurs as &$j) {
            $j['buts'] = $buts[$j['id']] ?? 0;
            $j['passes'] = $passes[$j['id']] ?? 0;
            $j['entrees'] = $entrees[$j['id']] ?? 0;
            $j['sorties'] = $sorties[$j['id']] ?? 0;
            $j['note'] = $j['notes'] ? round(array_sum($j['notes']) / \count($j['notes']), 1) : null;
            // Parts du temps de jeu et des convocations, par rapport aux matchs de la sélection.
            $j['partTemps'] = $minutesPossibles ? round(100 * $j['minutes'] / $minutesPossibles, 1) : null;
            $j['partConvocations'] = $matchs ? round(100 * $j['convocations'] / $matchs) : null;
            unset($j['notes']);
        }
        unset($j);

        return [
            'joueurs' => array_values($joueurs),
            'matchs' => $matchs,
            'convoques' => \count($joueurs),
            'utilises' => \count(array_filter($joueurs, fn (array $j) => $j['matchs'] > 0)),
            'dureeReference' => $dureeReference,
            'minutesPossibles' => $minutesPossibles,
        ];
    }

    /**
     * Cartons de la sélection, du plus récent au plus ancien.
     *
     * @param list<string>|null $secteurs
     *
     * @return list<array{id: int, nom: string, prenom: string, carton: string, date: \DateTimeImmutable|null, rencontre: int}>
     */
    public function cartons(?array $secteurs, ?string $saison, ?int $categorieId): array
    {
        $qb = $this->createQueryBuilder('p')
            ->select('j.id, j.nom, j.prenom, p.carton, r.date, r.id AS rencontre')
            ->join('p.rencontre', 'r')
            ->join('p.joueur', 'j')
            ->andWhere('p.carton IS NOT NULL')
            ->orderBy('r.date', 'DESC')
            ->addOrderBy('j.nom');

        return $this->rencontres->filtrer($qb, $secteurs, $saison, $categorieId)->getQuery()->getArrayResult();
    }

    /**
     * @param 'entrant'|'sortant' $role
     * @param list<string>|null   $secteurs
     *
     * @return array<int, int> id du joueur => nombre
     */
    private function compterRemplacements(string $role, ?array $secteurs, ?string $saison, ?int $categorieId): array
    {
        $qb = $this->getEntityManager()->createQueryBuilder()
            ->select("IDENTITY(x.$role) AS joueur, COUNT(x.id) AS total")
            ->from(Remplacement::class, 'x')
            ->join('x.rencontre', 'r')
            ->andWhere("x.$role IS NOT NULL")
            ->groupBy("x.$role");
        $resultat = $this->rencontres->filtrer($qb, $secteurs, $saison, $categorieId)->getQuery()->getArrayResult();

        return array_column($resultat, 'total', 'joueur');
    }

    /**
     * @param 'buteur'|'passeur'  $role
     * @param list<string>|null $secteurs
     *
     * @return array<int, int> id du joueur => nombre
     */
    private function compterButs(string $role, ?array $secteurs, ?string $saison, ?int $categorieId): array
    {
        $qb = $this->getEntityManager()->createQueryBuilder()
            ->select("IDENTITY(b.$role) AS joueur, COUNT(b.id) AS total")
            ->from(But::class, 'b')
            ->join('b.rencontre', 'r')
            ->andWhere("b.$role IS NOT NULL")
            ->andWhere('b.adverse = false')
            ->groupBy("b.$role");
        $resultat = $this->rencontres->filtrer($qb, $secteurs, $saison, $categorieId)->getQuery()->getArrayResult();

        return array_column($resultat, 'total', 'joueur');
    }

    /** @return list<Participation> du plus récent au plus ancien */
    public function parJoueur(Joueur $joueur): array
    {
        return $this->createQueryBuilder('p')
            ->addSelect('r')
            ->join('p.rencontre', 'r')
            ->andWhere('p.joueur = :j')
            ->setParameter('j', $joueur)
            ->orderBy('r.date', 'DESC')
            ->getQuery()
            ->getResult();
    }
}
