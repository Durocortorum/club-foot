<?php

namespace App\Controller;

use App\Entity\Categorie;
use App\Repository\JoueurRepository;
use App\Security\JoueurVoter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class AccueilController extends AbstractController
{
    #[Route('/', name: 'app_accueil')]
    public function index(JoueurRepository $joueurs, JoueurVoter $voter): Response
    {
        $visibles = $voter->secteursVisibles();
        $secteurs = [];
        foreach ($joueurs->compterActifsParCategorie() as $ligne) {
            if (\in_array($ligne['secteur'], $visibles, true)) {
                $secteurs[$ligne['secteur']][] = $ligne;
            }
        }

        return $this->render('accueil/index.html.twig', [
            'secteurs' => $secteurs,
            'libelles' => Categorie::SECTEURS,
        ]);
    }
}
