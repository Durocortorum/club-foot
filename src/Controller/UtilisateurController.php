<?php

namespace App\Controller;

use App\Entity\Utilisateur;
use App\Form\ChangementMotDePasseType;
use App\Form\UtilisateurType;
use App\Repository\UtilisateurRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Gestion des comptes (administrateurs) et changement de son propre mot de passe.
 */
class UtilisateurController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly UserPasswordHasherInterface $hasher,
    ) {
    }

    #[Route('/utilisateurs', name: 'app_utilisateur_index', methods: ['GET'])]
    #[IsGranted('ROLE_ADMIN')]
    public function index(UtilisateurRepository $utilisateurs): Response
    {
        return $this->render('utilisateur/index.html.twig', [
            'utilisateurs' => $utilisateurs->findBy([], ['nom' => 'ASC', 'prenom' => 'ASC']),
        ]);
    }

    #[Route('/utilisateurs/nouveau', name: 'app_utilisateur_nouveau', methods: ['GET', 'POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function nouveau(Request $request): Response
    {
        return $this->formulaire($request, new Utilisateur(), 'Nouveau compte');
    }

    #[Route('/utilisateurs/{id}/modifier', name: 'app_utilisateur_modifier', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function modifier(Request $request, Utilisateur $utilisateur): Response
    {
        return $this->formulaire($request, $utilisateur, 'Compte de ' . $utilisateur);
    }

    #[Route('/utilisateurs/{id}/supprimer', name: 'app_utilisateur_supprimer', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function supprimer(Request $request, Utilisateur $utilisateur): Response
    {
        if ($utilisateur === $this->getUser()) {
            $this->addFlash('erreur', 'Vous ne pouvez pas supprimer votre propre compte.');
        } elseif ($this->isCsrfTokenValid('supprimer-utilisateur' . $utilisateur->getId(), $request->getPayload()->getString('_token'))) {
            $this->em->remove($utilisateur);
            $this->em->flush();
            $this->addFlash('succes', 'Compte supprimé.');
        }

        return $this->redirectToRoute('app_utilisateur_index');
    }

    #[Route('/mon-compte', name: 'app_mon_compte', methods: ['GET', 'POST'])]
    public function monCompte(Request $request): Response
    {
        /** @var Utilisateur $moi */
        $moi = $this->getUser();
        $form = $this->createForm(ChangementMotDePasseType::class);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $moi->setPassword($this->hasher->hashPassword($moi, $form->get('nouveau')->getData()));
            $this->em->flush();
            $this->addFlash('succes', 'Mot de passe modifié.');

            return $this->redirectToRoute('app_mon_compte');
        }

        return $this->render('utilisateur/mon_compte.html.twig', ['form' => $form, 'moi' => $moi]);
    }

    private function formulaire(Request $request, Utilisateur $utilisateur, string $titre): Response
    {
        $soiMeme = $utilisateur === $this->getUser();
        $form = $this->createForm(UtilisateurType::class, $utilisateur, [
            'mot_de_passe_obligatoire' => null === $utilisateur->getId(),
            'soi_meme' => $soiMeme,
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            if ($soiMeme && !$utilisateur->isAdmin()) {
                // La case est désactivée pour soi-même et n'est donc pas envoyée : on la rétablit.
                $utilisateur->setRolesAttribues([...$utilisateur->getRolesAttribues(), 'ROLE_ADMIN']);
            }
            if ($motDePasse = $form->get('motDePasse')->getData()) {
                $utilisateur->setPassword($this->hasher->hashPassword($utilisateur, $motDePasse));
            }
            $this->em->persist($utilisateur);
            $this->em->flush();
            $this->addFlash('succes', $motDePasse ? 'Compte enregistré, avec le nouveau mot de passe.' : 'Compte enregistré.');

            return $this->redirectToRoute('app_utilisateur_index');
        }

        $vue = ['form' => $form, 'titre' => $titre, 'utilisateur' => $utilisateur];
        if ($utilisateur->getId() && !$soiMeme) {
            $vue['suppression'] = [
                'action' => $this->generateUrl('app_utilisateur_supprimer', ['id' => $utilisateur->getId()]),
                'jeton' => 'supprimer-utilisateur' . $utilisateur->getId(),
                'question' => 'Supprimer le compte de ' . $utilisateur . ' ? Cette personne ne pourra plus se connecter.',
            ];
        }

        return $this->render('utilisateur/formulaire.html.twig', $vue);
    }
}
