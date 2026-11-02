<?php

namespace App\Controller;

use App\Entity\Club;
use App\Entity\Utilisateur;
use App\Form\InscriptionType;
use App\Repository\UtilisateurRepository;
use App\Service\CreationClub;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Page publique « Créer mon club » : un nouveau club démarre à zéro, son créateur en est l'administrateur.
 */
class InscriptionController extends AbstractController
{
    #[Route('/inscription', name: 'app_inscription', methods: ['GET', 'POST'])]
    public function inscription(Request $request, UtilisateurRepository $utilisateurs, CreationClub $creation, Security $security): Response
    {
        if ($this->getUser()) {
            return $this->redirectToRoute('app_accueil');
        }
        $form = $this->createForm(InscriptionType::class);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $donnees = $form->getData();
            $email = mb_strtolower(trim($donnees['email']));
            if ($donnees['site']) {
                // Robot : on fait comme si tout allait bien, sans rien créer.
                return $this->redirectToRoute('app_connexion');
            }
            if ($utilisateurs->tousClubs(['email' => $email])) {
                $form->get('email')->addError(new FormError('Un compte existe déjà avec cette adresse e-mail. Connectez-vous, ou utilisez une autre adresse.'));
            } else {
                $club = (new Club())->setNom($donnees['club']);
                $administrateur = (new Utilisateur())->setEmail($email)->setPrenom(trim($donnees['prenom']))->setNom(trim($donnees['nom']));
                $creation->creer($club, $administrateur, $donnees['motDePasse']);
                $security->login($administrateur, 'form_login', 'main');
                $this->addFlash('succes', 'Bienvenue ! Votre club « ' . $club->getNom() . ' » est créé. Suivez les premiers pas ci-dessous pour démarrer.');

                return $this->redirectToRoute('app_accueil');
            }
        }

        return $this->render('security/inscription.html.twig', ['form' => $form]);
    }
}
