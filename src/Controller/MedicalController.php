<?php

namespace App\Controller;

use App\Entity\Utilisateur;
use App\Service\AccesMedical;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Ouverture de l'accès aux données médicales par un code reçu par e-mail (voir AccesMedical).
 */
class MedicalController extends AbstractController
{
    public function __construct(private readonly AccesMedical $acces)
    {
    }

    #[Route('/medical/code', name: 'app_medical_code', methods: ['GET', 'POST'])]
    public function code(Request $request): Response
    {
        if (!$this->acces->aLeDroit()) {
            throw $this->createAccessDeniedException('Vous n\'avez pas de droit médical en cours.');
        }
        /** @var Utilisateur $moi */
        $moi = $this->getUser();
        $retour = $this->retour($request);

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('code-medical', $request->getPayload()->getString('_token'))) {
                $this->addFlash('erreur', 'La page a expiré : réessayez.');
            } elseif ('envoyer' === $request->getPayload()->getString('action')) {
                $erreur = $this->acces->envoyerCode($moi);
                $this->addFlash($erreur ? 'erreur' : 'succes', $erreur ?? \sprintf('Code envoyé à %s. Il est valable %d minutes.', $moi->getEmail(), AccesMedical::CODE_MINUTES));
            } else {
                $erreur = $this->acces->verifierCode($request->getPayload()->getString('code'));
                if (null === $erreur) {
                    $this->addFlash('succes', \sprintf('Accès aux données médicales ouvert pour %d minutes.', AccesMedical::OUVERTURE_MINUTES));

                    return $this->redirect($retour);
                }
                $this->addFlash('erreur', $erreur);
            }

            return $this->redirectToRoute('app_medical_code', ['retour' => $retour]);
        }

        if ($this->acces->estOuvert()) {
            return $this->redirect($retour);
        }

        return $this->render('medical/code.html.twig', ['moi' => $moi, 'retour' => $retour]);
    }

    #[Route('/medical/fermer', name: 'app_medical_fermer', methods: ['POST'])]
    public function fermer(Request $request): Response
    {
        if ($this->isCsrfTokenValid('fermer-medical', $request->getPayload()->getString('_token'))) {
            $this->acces->fermer();
            $this->addFlash('succes', 'Accès aux données médicales refermé.');
        }

        return $this->redirectToRoute('app_accueil');
    }

    /** Page où revenir après le code : seulement une adresse de ce site. */
    private function retour(Request $request): string
    {
        $retour = $request->query->getString('retour');

        return str_starts_with($retour, '/') && !str_starts_with($retour, '//') && !str_contains($retour, '\\')
            ? $retour
            : $this->generateUrl($this->isGranted('ROLE_INFIRMERIE_LECTURE') ? 'app_infirmerie_index' : 'app_accueil');
    }
}
