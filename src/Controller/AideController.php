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

/** Assistant d'aide : question posée dans la fenêtre « ? », réponse tirée du guide, en JSON (aide_controller.js). */
class AideController extends AbstractController
{
    #[Route('/aide/question', name: 'app_aide_question', methods: ['POST'])]
    public function question(Request $request, AssistantAide $assistant): JsonResponse
    {
        $donnees = json_decode($request->getContent(), true);
        if (!\is_array($donnees) || !$this->isCsrfTokenValid('aide', (string) ($donnees['jeton'] ?? ''))) {
            return new JsonResponse(['erreur' => 'Page expirée : rechargez-la.'], Response::HTTP_FORBIDDEN);
        }
        /** @var Utilisateur $moi */
        $moi = $this->getUser();
        try {
            return new JsonResponse(['reponse' => $assistant->repondre($moi, (string) ($donnees['question'] ?? ''))]);
        } catch (ErreurAide $e) {
            return new JsonResponse(['erreur' => $e->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }
    }
}
