<?php

namespace App\Controller;

use App\Entity\Joueur;
use App\Entity\Mesure;
use App\Entity\TestPhysique;
use App\Form\MesureType;
use App\Form\TestPhysiqueType;
use App\Security\JoueurVoter;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Mesures (poids, taille) et tests physiques d'un joueur. Saisis par l'infirmerie
 * ou par les éducateurs qui peuvent modifier la fiche du joueur.
 */
class SuiviPhysiqueController extends AbstractController
{
    #[Route('/joueurs/{id}/mesures/nouvelle', name: 'app_mesure_nouvelle', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function nouvelleMesure(Request $request, Joueur $joueur, EntityManagerInterface $em): Response
    {
        $this->verifier($joueur);
        $mesure = (new Mesure())->setJoueur($joueur)->setDate(new \DateTimeImmutable('today'));

        return $this->formulaire($request, $em, MesureType::class, $mesure, $joueur, 'Nouvelle mesure – ' . $joueur->getNomComplet());
    }

    #[Route('/joueurs/{id}/tests/nouveau', name: 'app_test_nouveau', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function nouveauTest(Request $request, Joueur $joueur, EntityManagerInterface $em): Response
    {
        $this->verifier($joueur);
        $test = (new TestPhysique())->setJoueur($joueur)->setDate(new \DateTimeImmutable('today'));

        return $this->formulaire($request, $em, TestPhysiqueType::class, $test, $joueur, 'Nouveau test physique – ' . $joueur->getNomComplet());
    }

    #[Route('/mesures/{id}/supprimer', name: 'app_mesure_supprimer', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function supprimerMesure(Request $request, Mesure $mesure, EntityManagerInterface $em): Response
    {
        return $this->supprimer($request, $em, $mesure, $mesure->getJoueur(), 'mesure');
    }

    #[Route('/tests/{id}/supprimer', name: 'app_test_supprimer', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function supprimerTest(Request $request, TestPhysique $test, EntityManagerInterface $em): Response
    {
        return $this->supprimer($request, $em, $test, $test->getJoueur(), 'test');
    }

    private function formulaire(Request $request, EntityManagerInterface $em, string $type, Mesure|TestPhysique $objet, Joueur $joueur, string $titre): Response
    {
        $form = $this->createForm($type, $objet);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $em->persist($objet);
            $em->flush();
            $this->addFlash('succes', 'Enregistré.');

            return $this->redirectToRoute('app_joueur_voir', ['id' => $joueur->getId(), '_fragment' => 'physique']);
        }

        return $this->render('commun/formulaire.html.twig', [
            'form' => $form,
            'titre' => $titre,
            'retour' => $this->generateUrl('app_joueur_voir', ['id' => $joueur->getId()]),
        ]);
    }

    private function supprimer(Request $request, EntityManagerInterface $em, Mesure|TestPhysique $objet, Joueur $joueur, string $nom): Response
    {
        $this->verifier($joueur);
        if ($this->isCsrfTokenValid('supprimer-' . $nom . $objet->getId(), $request->getPayload()->getString('_token'))) {
            $em->remove($objet);
            $em->flush();
            $this->addFlash('succes', 'Supprimé.');
        }

        return $this->redirectToRoute('app_joueur_voir', ['id' => $joueur->getId(), '_fragment' => 'physique']);
    }

    private function verifier(Joueur $joueur): void
    {
        if (!$this->isGranted('ROLE_INFIRMERIE') && !$this->isGranted(JoueurVoter::MODIFIER, $joueur)) {
            throw $this->createAccessDeniedException();
        }
    }
}
