<?php

namespace App\Controller;

use App\Entity\Categorie;
use App\Entity\Rencontre;
use App\Repository\CategorieRepository;
use App\Repository\EntrainementRepository;
use App\Repository\RencontreRepository;
use App\Repository\SeanceRepository;
use App\Security\ActiviteVoter;
use App\Security\JoueurVoter;
use App\Service\PlanningEntrainements;
use Doctrine\DBAL\Types\Types;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Calendrier du club, mois par mois : matchs, entraînements et séances préparées de toutes les équipes ou d'une seule.
 */
class CalendrierController extends AbstractController
{
    public const MOIS = ['janvier', 'février', 'mars', 'avril', 'mai', 'juin', 'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre'];

    #[Route('/calendrier', name: 'app_calendrier', methods: ['GET'])]
    public function index(
        Request $request,
        ActiviteVoter $activite,
        JoueurVoter $joueurs,
        CategorieRepository $categories,
        RencontreRepository $rencontres,
        EntrainementRepository $entrainements,
        PlanningEntrainements $planning,
        SeanceRepository $seances,
    ): Response {
        // Équipes visibles : celles des matchs que l'utilisateur peut voir (toutes avec le droit « rapport de match »).
        $secteurs = $activite->secteursMatchs();
        $visibles = null === $secteurs ? $categories->findBy([], ['ordre' => 'ASC']) : $categories->findBy(['secteur' => $secteurs], ['ordre' => 'ASC']);
        if (!$visibles) {
            throw $this->createAccessDeniedException();
        }
        $choisie = array_values(array_filter($visibles, fn (Categorie $c) => $c->getId() === (int) $request->query->getString('categorie')))[0] ?? null;
        $equipes = $choisie ? [$choisie] : $visibles;

        $mois = \DateTimeImmutable::createFromFormat('!Y-m', $request->query->getString('mois')) ?: new \DateTimeImmutable('first day of this month midnight');
        $premier = $mois->modify('first day of this month');
        $dernier = $mois->modify('last day of this month');
        // Grille du lundi précédant le 1er au dimanche suivant le dernier jour.
        $debut = $premier->modify('-' . ((int) $premier->format('N') - 1) . ' days');
        $fin = $dernier->modify('+' . (7 - (int) $dernier->format('N')) . ' days');

        // Entraînements à venir créés d'après les créneaux jusqu'à la fin de la grille (sans dépasser la saison),
        // pour les équipes dont l'utilisateur gère le planning.
        $aujourdhui = new \DateTimeImmutable('today');
        $planifiables = array_values(array_filter($equipes, fn (Categorie $c) => \in_array($c->getSecteur(), $joueurs->secteursModifiables(), true)));
        $finSaison = Rencontre::bornesSaison(Rencontre::saisonDe($aujourdhui))[1];
        if ($planifiables && $fin >= $aujourdhui && $debut <= $finSaison) {
            $planning->aVenir($planifiables, (int) $aujourdhui->diff(min($fin, $finSaison))->days);
        }

        $jours = [];
        foreach ($rencontres->createQueryBuilder('r')
            ->andWhere('r.categorie IN (:c)')->setParameter('c', $equipes)
            ->andWhere('r.date BETWEEN :debut AND :fin')
            ->setParameter('debut', $debut, Types::DATE_IMMUTABLE)->setParameter('fin', $fin, Types::DATE_IMMUTABLE)
            ->getQuery()->getResult() as $r) {
            $jours[$r->getDate()->format('Y-m-d')][] = ['type' => 'match', 'heure' => $this->heure($r->getHeure()), 'objet' => $r];
        }
        // Séances préparées (thème) : rattachées à l'entraînement de l'équipe ce jour-là, sinon affichées seules.
        $seancesDuJour = [];
        foreach ($seances->createQueryBuilder('s')
            ->andWhere('s.categorie IN (:c)')->setParameter('c', $equipes)
            ->andWhere('s.date BETWEEN :debut AND :fin')
            ->setParameter('debut', $debut, Types::DATE_IMMUTABLE)->setParameter('fin', $fin, Types::DATE_IMMUTABLE)
            ->getQuery()->getResult() as $s) {
            $seancesDuJour[$s->getCategorie()->getId() . '|' . $s->getDate()->format('Y-m-d')][] = $s;
        }
        foreach ($entrainements->entre($equipes, $debut, $fin) as $e) {
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
        foreach ($jours as &$activites) {
            // Dans la journée : par heure (heure inconnue en dernier), les matchs avant les entraînements à égalité.
            $rang = ['match' => 0, 'entrainement' => 1, 'seance' => 2];
            usort($activites, fn (array $a, array $b) => [$a['heure'] ?? '99', $rang[$a['type']]] <=> [$b['heure'] ?? '99', $rang[$b['type']]]);
        }
        unset($activites);

        $semaines = [];
        for ($jour = $debut; $jour <= $fin; $jour = $jour->modify('+1 day')) {
            $semaines[intdiv((int) $debut->diff($jour)->days, 7)][] = $jour;
        }

        return $this->render('calendrier/index.html.twig', [
            'categories' => $visibles,
            'choisie' => $choisie,
            'mois' => $premier,
            'titre' => ucfirst(self::MOIS[(int) $premier->format('n') - 1]) . ' ' . $premier->format('Y'),
            'precedent' => $premier->modify('-1 month')->format('Y-m'),
            'suivant' => $premier->modify('+1 month')->format('Y-m'),
            'semaines' => $semaines,
            'jours' => $jours,
            'aujourdhui' => $aujourdhui->format('Y-m-d'),
            'peut_planifier' => [] !== $joueurs->secteursModifiables(),
        ]);
    }

    /** « 15h00 », « 15:00 » ou « 15h » → « 15:00 », pour trier et afficher les heures de la même façon. */
    private function heure(?string $heure): ?string
    {
        return $heure && preg_match('/^(\d{1,2})\s*[h:]\s*(\d{2})?/i', $heure, $m) ? \sprintf('%02d:%s', (int) $m[1], $m[2] ?? '00') : null;
    }
}
