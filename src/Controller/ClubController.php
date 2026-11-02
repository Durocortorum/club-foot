<?php

namespace App\Controller;

use App\Form\ClubType;
use App\Service\Apparence;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Logo et couleurs du club. Le logo est public (page de connexion, onglet du navigateur).
 */
class ClubController extends AbstractController
{
    #[Route('/club', name: 'app_club', methods: ['GET', 'POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function parametres(Request $request, Apparence $apparence, EntityManagerInterface $em): Response
    {
        $club = $apparence->club();
        $form = $this->createForm(ClubType::class, $club);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $fichier = $form->get('fichierLogo')->getData();
            try {
                if ($fichier) {
                    $club->setLogo($apparence->enregistrerLogo($fichier));
                } elseif ($form->get('logoOrigine')->getData()) {
                    $apparence->supprimerLogo($club->getLogo());
                    $club->setLogo(null);
                }
                $em->persist($club->marquerModifie());
                $em->flush();
                $this->addFlash('succes', 'Apparence du club enregistrée.');

                return $this->redirectToRoute('app_club');
            } catch (\InvalidArgumentException) {
                $this->addFlash('erreur', 'Ce fichier n\'est pas une image lisible.');
            }
        }

        return $this->render('club/parametres.html.twig', ['form' => $form, 'club' => $club]);
    }

    #[Route('/club/logo/{version}', name: 'app_club_logo', requirements: ['version' => '\d+'], methods: ['GET'])]
    public function logo(Apparence $apparence, Request $request): Response
    {
        return $this->image($apparence->chemin('logo'), $request);
    }

    #[Route('/club/icone/{version}', name: 'app_club_icone', requirements: ['version' => '\d+'], methods: ['GET'])]
    public function icone(Apparence $apparence, Request $request): Response
    {
        return $this->image($apparence->chemin('icone'), $request);
    }

    private function image(?string $chemin, Request $request): Response
    {
        $reponse = new BinaryFileResponse($chemin ?? throw $this->createNotFoundException());
        // La version dans l'adresse change à chaque modification : on peut garder l'image longtemps.
        $reponse->setPublic();
        $reponse->setMaxAge(31536000);
        $reponse->setAutoLastModified();
        $reponse->isNotModified($request);

        return $reponse;
    }
}
