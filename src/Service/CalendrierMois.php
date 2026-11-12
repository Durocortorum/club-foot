<?php

namespace App\Service;

use App\Entity\Categorie;
use App\Entity\Rencontre;
use App\Repository\EntrainementRepository;
use App\Repository\EvenementRepository;
use App\Repository\RencontreRepository;
use App\Repository\SeanceRepository;
use App\Repository\StageRepository;
use App\Repository\TournoiRepository;
use Doctrine\DBAL\Types\Types;

/**
 * Calendrier d'un mois, commun à l'encadrement (page Calendrier) et aux familles (espace famille) :
 * grille du lundi au dimanche, et activités de chaque jour (matchs, entraînements, séances, événements, stages, tournois).
 */
class CalendrierMois
{
    public const MOIS = ['janvier', 'février', 'mars', 'avril', 'mai', 'juin', 'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre'];

    public function __construct(
        private readonly RencontreRepository $rencontres,
        private readonly EntrainementRepository $entrainements,
        private readonly SeanceRepository $seances,
        private readonly EvenementRepository $evenements,
        private readonly StageRepository $stages,
        private readonly TournoiRepository $tournois,
        private readonly PlanningEntrainements $planning,
    ) {
    }

    /**
     * Grille du mois « AAAA-MM » (mois en cours si invalide) : du lundi précédant le 1er au dimanche suivant le dernier jour.
     *
     * @return array{mois: \DateTimeImmutable, debut: \DateTimeImmutable, fin: \DateTimeImmutable, titre: string, precedent: string, suivant: string, semaines: list<list<\DateTimeImmutable>>}
     */
    public function grille(string $mois): array
    {
        $choisi = \DateTimeImmutable::createFromFormat('!Y-m', $mois) ?: new \DateTimeImmutable('first day of this month midnight');
        $premier = $choisi->modify('first day of this month');
        $dernier = $choisi->modify('last day of this month');
        $debut = $premier->modify('-' . ((int) $premier->format('N') - 1) . ' days');
        $fin = $dernier->modify('+' . (7 - (int) $dernier->format('N')) . ' days');
        $semaines = [];
        for ($jour = $debut; $jour <= $fin; $jour = $jour->modify('+1 day')) {
            $semaines[intdiv((int) $debut->diff($jour)->days, 7)][] = $jour;
        }

        return [
            'mois' => $premier,
            'debut' => $debut,
            'fin' => $fin,
            'titre' => ucfirst(self::MOIS[(int) $premier->format('n') - 1]) . ' ' . $premier->format('Y'),
            'precedent' => $premier->modify('-1 month')->format('Y-m'),
            'suivant' => $premier->modify('+1 month')->format('Y-m'),
            'semaines' => $semaines,
        ];
    }

    /**
     * Crée d'après les créneaux les entraînements à venir jusqu'à la fin de la grille (sans dépasser la saison).
     *
     * @param list<Categorie> $equipes
     */
    public function planifier(array $equipes, \DateTimeImmutable $fin): void
    {
        $aujourdhui = new \DateTimeImmutable('today');
        $finSaison = Rencontre::bornesSaison(Rencontre::saisonDe($aujourdhui))[1];
        if ($equipes && $fin >= $aujourdhui) {
            $this->planning->aVenir($equipes, (int) $aujourdhui->diff(min($fin, $finSaison))->days);
        }
    }

    /**
     * Activités de chaque jour (« AAAA-MM-JJ » => liste triée par heure).
     *
     * @param list<Categorie>      $equipes          équipes dont on montre les matchs et entraînements
     * @param list<Categorie>|null $categories       événements, stages et tournois : de ces équipes ou de tout le club ; null : tous
     * @param bool                 $pourLesFamilles  sans les séances préparées ni les réunions internes
     *
     * @return array<string, list<array<string, mixed>>>
     */
    public function jours(array $equipes, \DateTimeImmutable $debut, \DateTimeImmutable $fin, ?array $categories, bool $pourLesFamilles = false): array
    {
        $jours = [];
        if ($equipes) {
            foreach ($this->rencontres->createQueryBuilder('r')
                ->andWhere('r.categorie IN (:c)')->setParameter('c', $equipes)
                ->andWhere('r.date BETWEEN :debut AND :fin')
                ->setParameter('debut', $debut, Types::DATE_IMMUTABLE)->setParameter('fin', $fin, Types::DATE_IMMUTABLE)
                ->getQuery()->getResult() as $r) {
                $jours[$r->getDate()->format('Y-m-d')][] = ['type' => 'match', 'heure' => self::heure($r->getHeure()), 'objet' => $r];
            }
        }
        // Séances préparées (thème) : rattachées à l'entraînement de l'équipe ce jour-là, sinon affichées seules.
        $seancesDuJour = [];
        if ($equipes && !$pourLesFamilles) {
            foreach ($this->seances->createQueryBuilder('s')
                ->andWhere('s.categorie IN (:c)')->setParameter('c', $equipes)
                ->andWhere('s.date BETWEEN :debut AND :fin')
                ->setParameter('debut', $debut, Types::DATE_IMMUTABLE)->setParameter('fin', $fin, Types::DATE_IMMUTABLE)
                ->getQuery()->getResult() as $s) {
                $seancesDuJour[$s->getCategorie()->getId() . '|' . $s->getDate()->format('Y-m-d')][] = $s;
            }
        }
        foreach ($this->entrainements->entre($equipes, $debut, $fin) as $e) {
            if (!$e->isSupprime()) {
                $cle = $e->getCategorie()->getId() . '|' . $e->getDate()->format('Y-m-d');
                $jours[$e->getDate()->format('Y-m-d')][] = ['type' => 'entrainement', 'heure' => $e->getHeureDebut(), 'objet' => $e, 'seances' => $seancesDuJour[$cle] ?? []];
                unset($seancesDuJour[$cle]);
            }
        }
        foreach ($seancesDuJour as $liste) {
            foreach ($liste as $s) {
                $jours[$s->getDate()->format('Y-m-d')][] = ['type' => 'seance', 'heure' => null, 'objet' => $s];
            }
        }
        // Événements, stages, tournois : sur chacun de leurs jours visibles dans la grille.
        foreach ($this->evenements->entre($debut, $fin, $categories, $pourLesFamilles) as $ev) {
            for ($j = max($ev->getDebut()->setTime(0, 0), $debut); $j <= min($ev->getDernierJour(), $fin); $j = $j->modify('+1 day')) {
                $premierJour = $j->format('Y-m-d') === $ev->getDebut()->format('Y-m-d');
                $jours[$j->format('Y-m-d')][] = ['type' => 'evenement', 'heure' => $premierJour ? $ev->getDebut()->format('H:i') : null, 'objet' => $ev];
            }
        }
        foreach ($this->stages->entre($debut, $fin, $categories) as $st) {
            for ($j = max($st->getDebut(), $debut); $j <= min($st->getDernierJour(), $fin); $j = $j->modify('+1 day')) {
                $jours[$j->format('Y-m-d')][] = ['type' => 'stage', 'heure' => null, 'objet' => $st, 'premier' => $j == $st->getDebut()];
            }
        }
        foreach ($this->tournois->entre($debut, $fin, $categories) as $to) {
            for ($j = max($to->getDebut(), $debut); $j <= min($to->getDernierJour(), $fin); $j = $j->modify('+1 day')) {
                $jours[$j->format('Y-m-d')][] = ['type' => 'tournoi', 'heure' => null, 'objet' => $to, 'premier' => $j == $to->getDebut()];
            }
        }
        // Dans la journée : par heure (heure inconnue en dernier), les matchs avant les entraînements à égalité.
        $rang = ['match' => 0, 'entrainement' => 1, 'evenement' => 2, 'stage' => 3, 'tournoi' => 3, 'seance' => 4];
        foreach ($jours as &$activites) {
            usort($activites, fn (array $a, array $b) => [$a['heure'] ?? '99', $rang[$a['type']]] <=> [$b['heure'] ?? '99', $rang[$b['type']]]);
        }
        unset($activites);

        return $jours;
    }

    /** « 15h00 », « 15:00 » ou « 15h » → « 15:00 », pour trier et afficher les heures de la même façon. */
    public static function heure(?string $heure): ?string
    {
        return $heure && preg_match('/^(\d{1,2})\s*[h:]\s*(\d{2})?/i', $heure, $m) ? \sprintf('%02d:%s', (int) $m[1], $m[2] ?? '00') : null;
    }
}
