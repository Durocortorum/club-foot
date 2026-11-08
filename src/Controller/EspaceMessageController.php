<?php

namespace App\Controller;

use App\Entity\Message;
use App\Entity\MessageReponse;
use App\Entity\Utilisateur;
use App\Repository\MessageRepository;
use App\Service\Messagerie;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Messagerie interne, côté famille : messages reçus de l'encadrement, et réponse dans un fil privé
 * (les autres familles ne voient ni la réponse, ni la conversation).
 */
#[Route('/espace/messages')]
class EspaceMessageController extends AbstractController
{
    public function __construct(
        private readonly MessageRepository $messages,
        private readonly EntityManagerInterface $em,
    ) {
    }

    #[Route('', name: 'app_espace_messages', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('espace/messages.html.twig', ['lignes' => $this->messages->recus($this->famille())]);
    }

    #[Route('/{id}', name: 'app_espace_message', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function voir(Message $message): Response
    {
        $moi = $this->famille();
        $this->verifier($message, $moi);
        $fil = $message->fil($moi);
        $html = $this->render('espace/message.html.twig', [
            'message' => $message,
            'fil' => $fil,
            'joueurs' => array_values(array_unique(array_map(
                fn ($d) => $d->getJoueur()->getPrenom(),
                array_filter($message->getDestinataires()->toArray(), fn ($d) => $d->getCompte() === $moi),
            ))),
        ]);
        // Affiché : le message et les réponses de l'encadrement sont lus.
        foreach ($message->getDestinataires() as $d) {
            if ($d->getCompte() === $moi) {
                $d->marquerLu();
            }
        }
        foreach ($fil as $r) {
            if ($r->isDeLEncadrement()) {
                $r->marquerLue();
            }
        }
        $this->em->flush();

        return $html;
    }

    #[Route('/{id}/repondre', name: 'app_espace_message_repondre', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function repondre(Request $request, Message $message, Messagerie $messagerie): Response
    {
        $moi = $this->famille();
        $this->verifier($message, $moi);
        if (!$this->isCsrfTokenValid('repondre-message' . $message->getId(), $request->getPayload()->getString('_token'))) {
            throw $this->createAccessDeniedException();
        }
        $texte = trim($request->getPayload()->getString('corps'));
        if ('' !== $texte) {
            $reponse = new MessageReponse($message, $moi, $moi, mb_substr($texte, 0, 3000));
            $this->em->persist($reponse);
            $this->em->flush();
            $messagerie->avertir($reponse);
            $this->addFlash('succes', 'Réponse envoyée à l\'encadrement.');
        }

        return $this->redirect($this->generateUrl('app_espace_message', ['id' => $message->getId()]) . '#reponses');
    }

    private function verifier(Message $message, Utilisateur $moi): void
    {
        if (!$message->estDestinataire($moi)) {
            throw $this->createNotFoundException();
        }
    }

    private function famille(): Utilisateur
    {
        /** @var Utilisateur $moi */
        $moi = $this->getUser();
        if (!$moi->isFamille()) {
            throw $this->createAccessDeniedException('Messages de l\'espace famille : connectez-vous avec un compte famille.');
        }

        return $moi;
    }
}
