<?php

namespace App\Controller;

use App\Entity\Categorie;
use App\Entity\Rencontre;
use App\Repository\CategorieRepository;
use App\Security\ActiviteVoter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Import en un clic depuis le site de la FFF : le bouton « ➜ Club Foot » (favori du navigateur) lit
 * la page Calendrier ou Classement affichée et l'ouvre ici ; on choisit l'équipe, puis on continue
 * vers l'import des matchs ou du classement habituel (aperçu avant enregistrement).
 * Le site de la FFF refuse les accès automatiques depuis un serveur : la lecture se fait dans le navigateur.
 */
class ImportFffController extends AbstractController
{
    public function __construct(private readonly ActiviteVoter $activite)
    {
    }

    #[Route('/import-fff', name: 'app_import_fff', methods: ['GET'])]
    public function index(CategorieRepository $categories): Response
    {
        $secteurs = $this->activite->secteursMatchs();
        if ([] === $secteurs) {
            throw $this->createAccessDeniedException();
        }
        $visibles = null === $secteurs ? $categories->findBy([], ['ordre' => 'ASC']) : $categories->findBy(['secteur' => $secteurs], ['ordre' => 'ASC']);
        $adresse = $this->generateUrl('app_import_fff', [], UrlGeneratorInterface::ABSOLUTE_URL);
        $code = $this->renderView('import_fff/_bouton.js.twig', ['adresse' => $adresse]);
        // Code sur une ligne, sans commentaires Twig ni indentation, encodé pour un lien « javascript: ».
        $code = trim(preg_replace('/\s*\n\s*/', ' ', $code));

        return $this->render('import_fff/index.html.twig', [
            'categories' => $visibles,
            'saison' => Rencontre::saisonDe(new \DateTimeImmutable('today')),
            'bouton' => 'javascript:' . rawurlencode($code),
        ]);
    }
}
