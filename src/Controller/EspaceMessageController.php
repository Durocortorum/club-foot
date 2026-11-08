<?php

namespace App\Controller;

use App\Entity\Message;
use App\Entity\MessageReponse;
use App\Entity\Utilisateur;
use App\Repository\MessageRepository;
use App\Repository\UtilisateurRepository;
use App\Service\AccesFamille;
use App\Service\Messagerie;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Messagerie interne, côté famille (parent ou joueur) : messages reçus de l'encadrement, réponse dans un fil
 * privé, et nouveau message à l'encadrement de la catégorie (éducateurs du secteur, direction du club).
 * Une famille n'écrit jamais aux autres familles.
 */
#[Route('/espace/messages')]
class EspaceMessageController extends AbstractController
{
    public function __construct(
        private readonly MessageRepository $messages,
        private readonly EntityManagerInterface $em,
        private readonly Messagerie $messagerie,
    ) {
    }

    #[Route('', name: 'app_espace_messages', methods: ['GET'])]
    public function index(): Response
    {
        $moi = $this->famille();

        return $this->render('espace/messages.html.twig', [
            'lignes' => $this->messages->recus($moi),
            'envoyes' => $this->messages->envoyesPar($moi),
        ]);
    }

    #[Route('/nouveau', name: 'app_espace_message_nouveau', methods: ['GET', 'POST'])]
    public function nouveau(Request $request, UtilisateurRepository $utilisateurs, AccesFamille $acces): Response
    {
        $moi = $this->famille();
        $groupes = $this->messagerie->interlocuteurs($moi, $utilisateurs->findBy(['famille' => false], ['nom' => 'ASC', 'prenom' => 'ASC']));
        $possibles = [];
        foreach ($groupes as $comptes) {
            foreach ($comptes as $u) {
                $possibles[$u->getId()] = $u;
            }
        }
        $saisie = ['sujet' => '', 'corps' => '', 'a' => []];
        $erreurs = [];

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('message-famille', $request->getPayload()->getString('_token'))) {
                throw $this->createAccessDeniedException();
            }
            $saisie = [
                'sujet' => trim($request->getPayload()->getString('sujet')),
                'corps' => trim($request->getPayload()->getString('corps')),
                'a' => array_values(array_intersect_key($possibles, array_flip(array_map('intval', $request->getPayload()->all('a'))))),
            ];
            if (!$saisie['a']) {
                $erreurs['a'] = 'Choisissez au moins une personne.';
            }
            if ('' === $saisie['sujet']) {
                $erreurs['sujet'] = 'Indiquez l\'objet du message.';
            }
            if ('' === $saisie['corps']) {
                $erreurs['corps'] = 'Le message est vide.';
            }
            if (!$erreurs) {
                $message = (new Message())->setAuteur($moi)->setAuteurNom($this->messagerie->nomFamille($moi))
                    ->setSujet(mb_substr($saisie['sujet'], 0, 120))->setCorps(mb_substr($saisie['corps'], 0, 5000))
                    ->setCible('encadrement')
                    ->setCibleLibelle(implode(', ', array_map(fn (Utilisateur $u) => (string) $u, $saisie['a'])));
                $this->messagerie->envoyer($message, [], true, $saisie['a']);
                $this->addFlash('succes', 'Message envoyé. La réponse arrivera ici, dans vos messages.');

                return $this->redirectToRoute('app_espace_message', ['id' => $message->getId()]);
            }
        }

        return $this->render('espace/nouveau_message.html.twig', [
            'groupes' => $groupes,
            'saisie' => $saisie,
            'choisis' => array_map(fn (Utilisateur $u) => $u->getId(), $saisie['a']),
            'erreurs' => $erreurs,
        ], new Response(null, $erreurs ? 422 : 200));
    }

    #[Route('/{id}', name: 'app_espace_message', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function voir(Message $message): Response
    {
        $moi = $this->famille();
        if ($message->getAuteur() === $moi) {
            return $this->voirEnvoye($message);
        }
        $this->verifier($message, $moi);
        $fil = $message->fil($moi);
        $html = $this->render('espace/message.html.twig', [
            'message' => $message,
            'fil' => $fil,
            'joueurs' => array_values(array_unique(array_map(
                fn ($d) => $d->getJoueur()?->getPrenom(),
                array_filter($message->getDestinataires()->toArray(), fn ($d) => $d->getCompte() === $moi && $d->getJoueur()),
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

    /** Réponse de la famille : dans son fil (message reçu), ou dans le fil d'un destinataire (message qu'elle a écrit). */
    #[Route('/{id}/repondre/{a}', name: 'app_espace_message_repondre', requirements: ['id' => '\d+', 'a' => '\d+'], defaults: ['a' => null], methods: ['POST'])]
    public function repondre(Request $request, Message $message, ?int $a, UtilisateurRepository $utilisateurs): Response
    {
        $moi = $this->famille();
        if ($message->getAuteur() === $moi) {
            $fil = $a ? $utilisateurs->find($a) : null;
            if (!$fil || !$message->estDestinataire($fil)) {
                throw $this->createNotFoundException();
            }
        } else {
            $this->verifier($message, $moi);
            $fil = $moi;
        }
        if (!$this->isCsrfTokenValid('repondre-message' . $message->getId() . '-' . $fil->getId(), $request->getPayload()->getString('_token'))) {
            throw $this->createAccessDeniedException();
        }
        $texte = trim($request->getPayload()->getString('corps'));
        if ('' !== $texte) {
            $reponse = new MessageReponse($message, $fil, $moi, mb_substr($texte, 0, 3000), $this->messagerie->nomFamille($moi));
            $this->em->persist($reponse);
            $this->em->flush();
            $this->messagerie->avertir($reponse);
            $this->addFlash('succes', 'Réponse envoyée.');
        }

        return $this->redirect($this->generateUrl('app_espace_message', ['id' => $message->getId()]) . '#fil-' . $fil->getId());
    }

    /** Message écrit par la famille : une conversation par destinataire ; les réponses affichées sont lues. */
    private function voirEnvoye(Message $message): Response
    {
        $fils = [];
        foreach ($message->destinatairesEncadrement() as $d) {
            $fils[] = ['destinataire' => $d, 'reponses' => $message->fil($d->getCompte())];
        }
        $html = $this->render('espace/message_envoye.html.twig', ['message' => $message, 'fils' => $fils]);
        foreach ($message->getReponses() as $r) {
            if (!$r->isDeLEncadrement()) {
                $r->marquerLue();
            }
        }
        $this->em->flush();

        return $html;
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
