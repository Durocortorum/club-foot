<?php

namespace App\Controller;

use App\Entity\Categorie;
use App\Repository\CategorieRepository;
use App\Security\JoueurVoter;
use App\Service\SuiviRessenti;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Autoévaluation des joueurs (RPE) vue par l'encadrement : pour une équipe et une semaine, la note de chaque
 * joueur à chaque séance, la moyenne de la séance, la charge de la semaine (RPE × minutes) et sa hausse
 * par rapport aux quatre semaines précédentes.
 */
class RessentiController extends AbstractController
{
    #[Route('/ressentis', name: 'app_ressenti', methods: ['GET'])]
    public function index(Request $request, CategorieRepository $categories, JoueurVoter $voter, SuiviRessenti $suivi): Response
    {
        $visibles = $categories->findBy(['secteur' => $voter->secteursVisibles() ?: ['-']], ['ordre' => 'ASC']);
        if (!$visibles) {
            throw $this->createAccessDeniedException();
        }
        $id = $request->query->getString('categorie');
        $categorie = array_values(array_filter($visibles, fn (Categorie $c) => ctype_digit($id) && $c->getId() === (int) $id))[0] ?? $visibles[0];
        $jour = \DateTimeImmutable::createFromFormat('!Y-m-d', $request->query->getString('semaine')) ?: new \DateTimeImmutable('today');
        $lundi = $jour->modify('monday this week');

        return $this->render('ressenti/index.html.twig', [
            'categories' => $visibles,
            'categorie' => $categorie,
            'lundi' => $lundi,
            'precedente' => $lundi->modify('-7 days'),
            'suivante' => $lundi->modify('+7 days'),
            'tableau' => $suivi->semaine($categorie, $lundi),
            'seuil' => SuiviRessenti::SEUIL_HAUSSE,
        ]);
    }
}
