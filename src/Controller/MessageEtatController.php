<?php

namespace App\Controller;

use App\Entity\Utilisateur;
use App\Repository\MessageRepository;
use App\Security\JoueurVoter;
use App\Twig\MessagerieExtension;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * État de la messagerie, demandé régulièrement par la page (nouvelles_controller.js) : nombre de non-lus
 * pour la pastille du menu et, sur une conversation ouverte, nombre de réponses visibles (si ce nombre change,
 * la page se met à jour). Ne marque rien comme lu.
 */
class MessageEtatController extends AbstractController
{
    #[Route('/espace/messages/etat', name: 'app_messages_etat', methods: ['GET'])]
    public function etat(Request $request, MessageRepository $messages, MessagerieExtension $compteur, JoueurVoter $joueurs): JsonResponse
    {
        /** @var Utilisateur $moi */
        $moi = $this->getUser();
        $etat = ['nonLus' => $compteur->nonLus()];

        $message = $request->query->getInt('message') ? $messages->find($request->query->getInt('message')) : null;
        if ($message) {
            $secteurs = $this->isGranted('ROLE_ADMIN') ? null : $joueurs->secteursModifiables();
            $destinataire = $message->estDestinataire($moi) && $message->getAuteur() !== $moi;
            $voit = $message->getAuteur() === $moi || $destinataire || (!$moi->isFamille() && $messages->estVisible($message, $moi, $secteurs));
            if ($voit) {
                $etat['fil'] = $destinataire ? \count($message->fil($moi)) : $message->getReponses()->count();
            }
        }

        return new JsonResponse($etat, headers: ['Cache-Control' => 'no-store']);
    }
}
