<?php

namespace App\Controller;

use App\Entity\ConnexionHelloAsso;
use App\Entity\Joueur;
use App\Entity\PaiementEnLigne;
use App\Entity\Utilisateur;
use App\Repository\ConnexionHelloAssoRepository;
use App\Repository\PaiementEnLigneRepository;
use App\Service\ErreurHelloAsso;
use App\Service\PaiementsEnLigne;
use App\Service\ProchesJoueur;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Paiement en ligne depuis l'espace famille (HelloAsso) : départ vers la page de paiement, retour après paiement,
 * et adresse de notification que HelloAsso appelle à chaque paiement ou échéance.
 */
class EspacePaiementController extends AbstractController
{
    public function __construct(
        private readonly PaiementsEnLigne $paiements,
        private readonly EntityManagerInterface $em,
    )
    {
    }

    /** La famille paie le reste de la cotisation de l'enfant, en une ou plusieurs fois. */
    #[Route('/espace/paiement/cotisation/{id}', name: 'app_espace_payer_cotisation', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function payerCotisation(Request $request, Joueur $joueur, ProchesJoueur $proches): Response
    {
        $moi = $this->moi();
        if (!$moi->isFamille() || !$proches->voitStatistiques($moi, $joueur) || !$this->isCsrfTokenValid('payer-cotisation' . $joueur->getId(), $request->getPayload()->getString('_token'))) {
            throw $this->createAccessDeniedException();
        }
        try {
            $r = $this->paiements->payerCotisation($joueur, $moi, $request->getPayload()->getInt('echeances', 1), fn (PaiementEnLigne $p) => [
                'retour' => $this->generateUrl('app_espace_paiement_retour', ['id' => $p->getId()], UrlGeneratorInterface::ABSOLUTE_URL),
                'erreur' => $this->generateUrl('app_espace_paiement_retour', ['id' => $p->getId(), 'erreur' => 1], UrlGeneratorInterface::ABSOLUTE_URL),
                'abandon' => $this->generateUrl('app_espace_paiement_retour', ['id' => $p->getId(), 'abandon' => 1], UrlGeneratorInterface::ABSOLUTE_URL),
            ]);
        } catch (ErreurHelloAsso $e) {
            $this->addFlash('erreur', 'Paiement en ligne impossible pour le moment : ' . $e->getMessage());

            return $this->redirectToRoute('app_espace', ['_fragment' => 'cotisation-' . $joueur->getId()]);
        }

        return $this->redirect($r['redirection']);
    }

    /** Retour de la page HelloAsso (paiement fait, abandonné ou en erreur) : l'état est relu chez HelloAsso. */
    #[Route('/espace/paiement/{id}/retour', name: 'app_espace_paiement_retour', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function retour(Request $request, PaiementEnLigne $paiement, ProchesJoueur $proches): Response
    {
        $moi = $this->moi();
        if ($paiement->getPayeur() !== $moi && !$proches->voitStatistiques($moi, $paiement->getJoueur())) {
            throw $this->createAccessDeniedException();
        }
        if ($this->paiements->synchroniser($paiement)) {
            $this->addFlash('succes', $paiement->getEcheances() > 1
                ? \sprintf('Merci ! Paiement en %d fois accepté : la première échéance est réglée, les suivantes seront prélevées chaque mois.', $paiement->getEcheances())
                : 'Merci ! Votre paiement est bien enregistré par le club.');
        } elseif ($request->query->has('abandon') || $request->query->has('erreur')) {
            $paiement->abandonner();
            $this->em->flush();
            $this->addFlash($request->query->has('erreur') ? 'erreur' : 'info', $request->query->has('erreur')
                ? 'Le paiement n\'a pas abouti : rien n\'a été débité. Vous pouvez réessayer.'
                : 'Paiement annulé : rien n\'a été débité.');
        } else {
            $this->addFlash('info', 'Paiement en cours de traitement chez HelloAsso : il apparaîtra ici dans quelques minutes.');
        }

        return $this->redirectToRoute('app_espace', ['_fragment' => 'cotisation-' . $paiement->getJoueur()->getId()]);
    }

    /**
     * Notification de HelloAsso (commande, paiement, échéance) : on retrouve le paiement concerné puis on relit son état
     * chez HelloAsso. Le contenu reçu ne sert qu'à le retrouver : rien n'est enregistré sur sa seule foi.
     */
    #[Route('/helloasso/notification/{jeton}', name: 'app_helloasso_notification', requirements: ['jeton' => '[0-9a-f]{40}'], methods: ['POST'])]
    public function notification(Request $request, string $jeton, ConnexionHelloAssoRepository $connexions, PaiementEnLigneRepository $enLigne): Response
    {
        $connexion = $connexions->findOneBy(['jetonNotification' => $jeton]);
        if (!$connexion instanceof ConnexionHelloAsso) {
            return new Response('', 404);
        }
        $donnees = json_decode($request->getContent(), true) ?: [];
        $data = \is_array($donnees['data'] ?? null) ? $donnees['data'] : [];
        $metadata = $donnees['metadata'] ?? $data['meta'] ?? $data['metadata'] ?? [];
        $paiement = null;
        if (\is_array($metadata) && !empty($metadata['paiement'])) {
            $paiement = $enLigne->find((int) $metadata['paiement']);
        }
        $commande = (int) ($data['order']['id'] ?? (('Order' === ($donnees['eventType'] ?? '')) ? ($data['id'] ?? 0) : 0));
        if (!$paiement && $commande) {
            $paiement = $enLigne->findOneBy(['commandeHelloAsso' => $commande]);
        }
        if (!$paiement && !empty($data['checkoutIntentId'])) {
            $paiement = $enLigne->findOneBy(['checkoutIntentId' => (int) $data['checkoutIntentId']]);
        }
        if ($paiement && $paiement->getClub()?->getId() === $connexion->getClub()?->getId()) {
            $this->paiements->synchroniser($paiement);
        }

        // Toujours 200 : HelloAsso n'a pas à réessayer une notification qui ne nous concerne pas.
        return new Response('ok');
    }

    private function moi(): Utilisateur
    {
        /** @var Utilisateur $moi */
        $moi = $this->getUser();

        return $moi;
    }
}
