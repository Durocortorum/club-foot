<?php

namespace App\Service;

use App\Entity\Joueur;
use App\Entity\Rencontre;
use App\Repository\JoueurRepository;
use App\Repository\RencontreRepository;
use Doctrine\DBAL\Types\Types;

/**
 * Ce que montre la page d'accueil de l'encadrement, pour ses secteurs : matchs de la semaine,
 * derniers résultats, anniversaires à venir, chiffres de la saison, prochain match de chaque équipe.
 */
class TableauAccueil
{
    /** Jours à l'avance pour les matchs et les anniversaires. */
    public const HORIZON = 7;

    public function __construct(
        private readonly RencontreRepository $rencontres,
        private readonly JoueurRepository $joueurs,
    ) {
    }

    /**
     * @param list<string> $secteurs secteurs visibles par l'utilisateur
     *
     * @return array{
     *     semaine: list<Rencontre>,
     *     resultats: list<Rencontre>,
     *     anniversaires: list<array{joueur: Joueur, date: \DateTimeImmutable, age: int, dans: int}>,
     *     prochains: array<int, Rencontre>,
     *     bilan: array{matchs: int, V: int, N: int, D: int, pour: int, contre: int},
     *     saison: string,
     * }
     */
    public function pour(array $secteurs): array
    {
        $aujourdhui = new \DateTimeImmutable('today');
        $saison = Rencontre::saisonDe($aujourdhui);

        return [
            'semaine' => $this->matchs($secteurs, $aujourdhui, $aujourdhui->modify('+' . self::HORIZON . ' days'), 'ASC'),
            'resultats' => array_values(array_filter(
                $this->matchs($secteurs, $aujourdhui->modify('-' . self::HORIZON . ' days'), $aujourdhui, 'DESC'),
                fn (Rencontre $r) => null !== $r->getResultat(),
            )),
            'anniversaires' => $this->anniversaires($secteurs, $aujourdhui),
            'prochains' => $this->prochains($secteurs, $aujourdhui),
            'bilan' => $this->rencontres->bilan($secteurs, $saison, null),
            'saison' => $saison,
        ];
    }

    /** @return list<Rencontre> */
    private function matchs(array $secteurs, \DateTimeImmutable $du, \DateTimeImmutable $au, string $ordre, int $limite = 40): array
    {
        return $this->rencontres->createQueryBuilder('r')
            ->join('r.categorie', 'c')->addSelect('c')
            ->andWhere('c.secteur IN (:secteurs)')->setParameter('secteurs', $secteurs ?: ['-'])
            ->andWhere('r.date BETWEEN :du AND :au')
            ->setParameter('du', $du, Types::DATE_IMMUTABLE)->setParameter('au', $au, Types::DATE_IMMUTABLE)
            ->orderBy('r.date', $ordre)->addOrderBy('r.heure', $ordre)->addOrderBy('c.ordre')
            ->setMaxResults($limite)
            ->getQuery()->getResult();
    }

    /**
     * Anniversaires des joueurs en activité dans les prochains jours (aujourd'hui compris).
     *
     * @return list<array{joueur: Joueur, date: \DateTimeImmutable, age: int, dans: int}>
     */
    private function anniversaires(array $secteurs, \DateTimeImmutable $aujourdhui): array
    {
        $joueurs = $this->joueurs->createQueryBuilder('j')
            ->join('j.categorie', 'c')->addSelect('c')
            ->andWhere('j.ancien = false AND j.dateNaissance IS NOT NULL')
            ->andWhere('c.secteur IN (:secteurs)')->setParameter('secteurs', $secteurs ?: ['-'])
            ->getQuery()->getResult();

        $liste = [];
        foreach ($joueurs as $j) {
            $naissance = $j->getDateNaissance();
            // Prochain anniversaire : cette année, ou l'an prochain s'il est passé (29 février → 1er mars).
            $prochain = self::anniversaireEn($naissance, (int) $aujourdhui->format('Y'));
            if ($prochain < $aujourdhui) {
                $prochain = self::anniversaireEn($naissance, (int) $aujourdhui->format('Y') + 1);
            }
            $dans = (int) $aujourdhui->diff($prochain)->days;
            if ($dans <= self::HORIZON) {
                $liste[] = ['joueur' => $j, 'date' => $prochain, 'age' => (int) $prochain->format('Y') - (int) $naissance->format('Y'), 'dans' => $dans];
            }
        }
        usort($liste, fn (array $a, array $b) => [$a['dans'], $a['joueur']->getNom()] <=> [$b['dans'], $b['joueur']->getNom()]);

        return $liste;
    }

    private static function anniversaireEn(\DateTimeImmutable $naissance, int $annee): \DateTimeImmutable
    {
        $mois = (int) $naissance->format('n');
        $jour = (int) $naissance->format('j');
        if (2 === $mois && 29 === $jour && !checkdate(2, 29, $annee)) {
            [$mois, $jour] = [3, 1];
        }

        return (new \DateTimeImmutable('today'))->setDate($annee, $mois, $jour);
    }

    /**
     * Prochain match de chaque catégorie (dans les 60 jours), par identifiant de catégorie.
     *
     * @return array<int, Rencontre>
     */
    private function prochains(array $secteurs, \DateTimeImmutable $aujourdhui): array
    {
        $prochains = [];
        foreach ($this->matchs($secteurs, $aujourdhui, $aujourdhui->modify('+60 days'), 'ASC', 400) as $r) {
            $prochains[$r->getCategorie()->getId()] ??= $r;
        }

        return $prochains;
    }
}
