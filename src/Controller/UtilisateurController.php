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
            // Les comptes famille se gèrent depuis les fiches joueurs (« Accès famille »).
            'utilisateurs' => $utilisateurs->findBy(['famille' => false], ['nom' => 'ASC', 'prenom' => 'ASC']),
            'familles' => $utilisateurs->count(['famille' => true]),
        ]);
    }

    /** Tableau des droits d'accès : une ligne par compte, une case à cliquer par section. */
    #[Route('/utilisateurs/droits', name: 'app_utilisateur_droits', methods: ['GET'])]
    #[IsGranted('ROLE_ADMIN')]
    public function droits(UtilisateurRepository $utilisateurs): Response
    {
        return $this->render('utilisateur/droits.html.twig', [
            'utilisateurs' => $utilisateurs->findBy(['famille' => false], ['nom' => 'ASC', 'prenom' => 'ASC']),
            'colonnes' => Utilisateur::colonnesDroits(),
        ]);
    }

    /**
     * Donne ou retire un droit depuis le tableau. Répond en JSON au clic (sans recharger la page),
     * ou par une redirection si le navigateur envoie le formulaire classique.
     */
    #[Route('/utilisateurs/{id}/droit', name: 'app_utilisateur_droit', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function basculerDroit(Request $request, Utilisateur $utilisateur): Response
    {
        $donnees = $request->getPayload();
        // « droit » : un rôle simple (ROLE_...) ou une section à deux niveaux (infirmerie, scolarite...).
        // « niveau » : 0 aucun, 1 oui (ou « voir » pour une section), 2 « modifier » (sections seulement).
        $droit = $donnees->getString('droit');
        $niveau = $donnees->getInt('niveau');
        $colonnes = array_column(Utilisateur::colonnesDroits(), null, 'cle');
        $colonne = $colonnes[$droit] ?? null;
        $json = \in_array('application/json', $request->getAcceptableContentTypes(), true);

        $erreur = match (true) {
            !$this->isCsrfTokenValid('droits-utilisateurs', $donnees->getString('_token')) => 'La page a expiré : rechargez-la.',
            $utilisateur->isFamille() || null === $colonne || $niveau < 0 || $niveau > (null === $colonne['niveaux'] ? 1 : 2) => 'Droit inconnu.',
            0 === $niveau && 'ROLE_ADMIN' === $droit && $utilisateur === $this->getUser() => 'Vous ne pouvez pas retirer votre propre accès à l\'administration.',
            default => null,
        };

        if (null === $erreur) {
            if (null === $colonne['niveaux']) {
                $utilisateur->basculerRole($droit, 1 === $niveau);
                $etat = $niveau ? 'accordé' : 'retiré';
            } else {
                $utilisateur->setNiveau($droit, $niveau);
                $etat = ['retiré', 'en consultation (voir)', 'en modification'][$niveau];
            }
            $this->em->flush();
            $message = sprintf('%s : « %s » %s.', $utilisateur, $colonne['libelle'], $etat);
        }

        if ($json) {
            return $this->json(
                null === $erreur
                    ? ['roles' => $utilisateur->getRolesAttribues(), 'message' => $message]
                    : ['erreur' => $erreur],
                null === $erreur ? 200 : 422,
            );
        }
        $this->addFlash(null === $erreur ? 'succes' : 'erreur', $erreur ?? $message);

        return $this->redirectToRoute('app_utilisateur_droits', [], 303);
    }

    #[Route('/utilisateurs/nouveau',name: 'app_utilisateur_nouveau', methods: ['GET', 'POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function nouveau(Request $request): Response
    {
        return $this->formulaire($request, new Utilisateur(), 'Nouveau compte');
    }

    #[Route('/utilisateurs/{id}/modifier', name: 'app_utilisateur_modifier', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function modifier(Request $request, Utilisateur $utilisateur): Response
    {
        if ($utilisateur->isFamille()) {
            throw $this->createNotFoundException();
        }

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
