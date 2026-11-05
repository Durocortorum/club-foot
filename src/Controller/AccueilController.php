<?php

namespace App\Controller;

use App\Entity\Categorie;
use App\Repository\CategorieRepository;
use App\Repository\JoueurRepository;
use App\Security\JoueurVoter;
use App\Service\PremiersPas;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class AccueilController extends AbstractController
{
    #[Route('/', name: 'app_accueil')]
    public function index(JoueurRepository $joueurs, CategorieRepository $categories, JoueurVoter $voter, PremiersPas $premiersPas): Response
    {
        if (!$this->isGranted('ROLE_ENCADREMENT')) {
            return $this->redirectToRoute('app_espace');
        }
        // Toutes les catégories des secteurs de l'utilisateur, même sans joueur (club qui démarre).
        $effectifs = array_column($joueurs->compterActifsParCategorie(), 'total', 'id');
        $secteurs = array_fill_keys(array_keys(Categorie::SECTEURS), []);
        foreach ($categories->findBy(['secteur' => $voter->secteursVisibles()], ['ordre' => 'ASC', 'nom' => 'ASC']) as $c) {
            $secteurs[$c->getSecteur()][] = ['id' => $c->getId(), 'nom' => $c->getNom(), 'total' => (int) ($effectifs[$c->getId()] ?? 0)];
        }
        $etapes = array_values(array_filter($premiersPas->etapes(), fn (array $e) => !$e['admin'] || $this->isGranted('ROLE_ADMIN')));

        return $this->render('accueil/index.html.twig', [
            'secteurs' => array_filter($secteurs),
            'libelles' => Categorie::SECTEURS,
            'etapes' => $etapes,
            'etapes_faites' => \count(array_filter($etapes, fn (array $e) => $e['fait'])),
        ]);
    }
}
