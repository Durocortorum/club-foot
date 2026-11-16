<?php

namespace App\Controller;

use App\Entity\Utilisateur;
use App\Service\Aide\AssistantAide;
use App\Service\Aide\ErreurAide;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/** Assistant d'aide : question posée dans la fenêtre « ? », réponse en JSON (aide_controller.js). */
class AideController extends AbstractController
{
    #[Route('/aide/question', name: 'app_aide_question', methods: ['POST'])]
    public function question(Request $request, AssistantAide $assistant): JsonResponse
    {
        if (!$assistant->estActif()) {
            return new JsonResponse(['erreur' => 'L\'assistant n\'est pas activé.'], Response::HTTP_NOT_FOUND);
        }
        $donnees = json_decode($request->getContent(), true);
        if (!\is_array($donnees) || !$this->isCsrfTokenValid('aide', (string) ($donnees['jeton'] ?? ''))) {
            return new JsonResponse(['erreur' => 'Page expirée : rechargez-la.'], Response::HTTP_FORBIDDEN);
        }
        /** @var Utilisateur $moi */
        $moi = $this->getUser();
        try {
            $reponse = $assistant->repondre($moi, (string) ($donnees['question'] ?? ''), \is_array($donnees['historique'] ?? null) ? array_values($donnees['historique']) : [], (string) ($donnees['page'] ?? ''));
        } catch (ErreurAide $e) {
            return new JsonResponse(['erreur' => $e->getMessage()], Response::HTTP_SERVICE_UNAVAILABLE);
        }

        return new JsonResponse(['reponse' => $reponse]);
    }
}
