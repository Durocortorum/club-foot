<?php

namespace App\Controller;

use App\Entity\Categorie;
use App\Repository\CategorieRepository;
use App\Security\ActiviteVoter;
use App\Security\JoueurVoter;
use App\Service\CalendrierMois;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Calendrier du club, mois par mois : matchs, entraînements et séances préparées de toutes les équipes ou d'une seule,
 * avec les événements, stages et tournois (voir CalendrierMois, partagé avec l'espace famille).
 */
class CalendrierController extends AbstractController
{
    public const MOIS = CalendrierMois::MOIS;

    #[Route('/calendrier', name: 'app_calendrier', methods: ['GET'])]
    public function index(Request $request, ActiviteVoter $activite, JoueurVoter $joueurs, CategorieRepository $categories, CalendrierMois $calendrier): Response
    {
        // Équipes visibles : celles des matchs que l'utilisateur peut voir (toutes avec le droit « rapport de match »).
        $secteurs = $activite->secteursMatchs();
        $visibles = null === $secteurs ? $categories->findBy([], ['ordre' => 'ASC']) : $categories->findBy(['secteur' => $secteurs], ['ordre' => 'ASC']);
        if (!$visibles) {
            throw $this->createAccessDeniedException();
        }
        $choisie = array_values(array_filter($visibles, fn (Categorie $c) => $c->getId() === (int) $request->query->getString('categorie')))[0] ?? null;
        $equipes = $choisie ? [$choisie] : $visibles;
        $grille = $calendrier->grille($request->query->getString('mois'));

        // Entraînements à venir créés d'après les créneaux, pour les équipes dont l'utilisateur gère le planning.
        $calendrier->planifier(array_values(array_filter($equipes, fn (Categorie $c) => \in_array($c->getSecteur(), $joueurs->secteursModifiables(), true))), $grille['fin']);

        return $this->render('calendrier/index.html.twig', $grille + [
            'categories' => $visibles,
            'choisie' => $choisie,
            'jours' => $calendrier->jours($equipes, $grille['debut'], $grille['fin'], $choisie ? [$choisie] : null),
            'aujourdhui' => (new \DateTimeImmutable('today'))->format('Y-m-d'),
            'peut_planifier' => [] !== $joueurs->secteursModifiables(),
        ]);
    }
}
