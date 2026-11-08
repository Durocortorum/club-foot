<?php

namespace App\Controller;

use App\Entity\Joueur;
use App\Entity\Proche;
use App\Entity\Utilisateur;
use App\Form\ProcheType;
use App\Service\AccesFamille;
use App\Service\ProchesJoueur;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Personnes à contacter d'un joueur majeur : ajout, modification, suppression par le joueur (son espace)
 * ou par l'encadrement qui peut modifier sa fiche ; accès à l'espace du joueur, donné ou retiré par le joueur seul.
 * Sous /espace pour être ouvert aux comptes famille (voir security.yaml).
 */
#[Route('/espace/proches')]
class ProcheController extends AbstractController
{
    public function __construct(
        private readonly ProchesJoueur $proches,
        private readonly EntityManagerInterface $em,
        private readonly AccesFamille $acces,
    ) {
    }

    #[Route('/joueur/{id}/ajouter', name: 'app_proche_ajouter', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function ajouter(Request $request, Joueur $joueur): Response
    {
        return $this->formulaire($request, new Proche($joueur), 'Ajouter une personne à contacter');
    }

    #[Route('/{id}/modifier', name: 'app_proche_modifier', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function modifier(Request $request, Proche $proche): Response
    {
        return $this->formulaire($request, $proche, 'Modifier ' . $proche);
    }

    #[Route('/{id}/supprimer', name: 'app_proche_supprimer', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function supprimer(Request $request, Proche $proche): Response
    {
        $this->verifierGestion($proche->getJoueur());
        if ($this->isCsrfTokenValid('proche' . $proche->getId(), $request->getPayload()->getString('_token'))) {
            $this->proches->supprimer($proche);
            $this->addFlash('succes', \sprintf('%s ne fait plus partie des personnes à contacter.', $proche));
        }

        return $this->retour($proche->getJoueur());
    }

    /** Le joueur donne à cette personne l'accès à son espace (lien par e-mail pour choisir son mot de passe). */
    #[Route('/{id}/acces', name: 'app_proche_acces', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function donnerAcces(Request $request, Proche $proche): Response
    {
        $joueur = $proche->getJoueur();
        if (!$this->proches->peutDonnerAcces($joueur) || !$proche->getEmail()
            || !$this->isCsrfTokenValid('proche' . $proche->getId(), $request->getPayload()->getString('_token'))) {
            throw $this->createAccessDeniedException();
        }
        try {
            $resultat = $this->acces->inviter($joueur, $proche->getEmail(), 'parent1');
            $this->addFlash('succes', match (true) {
                null === $resultat['lien'] => \sprintf('%s a déjà un compte : votre espace y est maintenant visible.', $proche->getEmail()),
                $resultat['envoye'] => \sprintf('Invitation envoyée à %s : un e-mail lui permet de choisir son mot de passe.', $proche->getEmail()),
                default => \sprintf('Accès préparé pour %s. Transmettez-lui ce lien pour choisir son mot de passe : %s', $proche->getEmail(), $resultat['lien']),
            });
        } catch (\DomainException $e) {
            $this->addFlash('erreur', $e->getMessage());
        }

        return $this->retour($joueur);
    }

    #[Route('/{id}/retirer-acces', name: 'app_proche_retirer_acces', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function retirerAcces(Request $request, Proche $proche): Response
    {
        $joueur = $proche->getJoueur();
        /** @var Utilisateur $moi */
        $moi = $this->getUser();
        $compte = $this->proches->compte($proche);
        if (!$this->proches->peutDonnerAcces($joueur) || !$compte || $compte === $moi
            || !$this->isCsrfTokenValid('proche' . $proche->getId(), $request->getPayload()->getString('_token'))) {
            throw $this->createAccessDeniedException();
        }
        $this->acces->retirer($joueur, $compte);
        $this->addFlash('succes', \sprintf('%s n\'a plus accès à votre espace.', $proche));

        return $this->retour($joueur);
    }

    private function formulaire(Request $request, Proche $proche, string $titre): Response
    {
        $joueur = $proche->getJoueur();
        $this->verifierGestion($joueur);
        $ancienEmail = $proche->getEmail();
        $compte = $proche->getId() ? $this->proches->compte($proche) : null;
        $form = $this->createForm(ProcheType::class, $proche);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            // Adresse changée : l'accès donné à l'ancienne adresse ne suit pas.
            if ($compte && $proche->getEmail() !== $ancienEmail) {
                $this->acces->retirer($joueur, $compte);
            }
            $this->em->persist($proche);
            $this->em->flush();
            $this->addFlash('succes', \sprintf('%s enregistré(e).', $proche));

            return $this->retour($joueur);
        }

        return $this->render('famille/proche.html.twig', [
            'form' => $form,
            'titre' => $titre,
            'joueur' => $joueur,
            'retour' => $this->urlRetour($joueur),
        ]);
    }

    private function verifierGestion(?Joueur $joueur): void
    {
        if (!$joueur || !$this->proches->peutGerer($joueur)) {
            throw $this->createAccessDeniedException();
        }
    }

    private function retour(Joueur $joueur): Response
    {
        return $this->redirect($this->urlRetour($joueur));
    }

    /** Le joueur revient à son espace ; l'encadrement, à la fiche du joueur. */
    private function urlRetour(Joueur $joueur): string
    {
        return $this->isGranted('ROLE_FAMILLE')
            ? $this->generateUrl('app_espace') . '#proches-' . $joueur->getId()
            : $this->generateUrl('app_joueur_voir', ['id' => $joueur->getId()]) . '#proches';
    }
}
