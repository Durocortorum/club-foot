<?php

namespace App\Controller;

use App\Command\ClubDemoCommand;
use App\Entity\Utilisateur;
use App\Service\ClubCourant;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Bouton « Remettre la démo à zéro », dans le seul club de démonstration : le club est recréé avec ses données
 * d'origine (dates du jour) et la personne reste connectée avec le même compte. Les autres clubs ne sont pas touchés.
 */
class DemoController extends AbstractController
{
    #[Route('/demo/remettre-a-zero', name: 'app_demo_remettre_a_zero', methods: ['POST'])]
    public function remettreAZero(Request $request, ClubCourant $clubCourant, ClubDemoCommand $commande, EntityManagerInterface $em, Security $security): Response
    {
        if (ClubDemoCommand::NOM !== $clubCourant->club()?->getNom()) {
            throw $this->createNotFoundException();
        }
        if (!$this->isCsrfTokenValid('demo-a-zero', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }
        /** @var Utilisateur $moi */
        $moi = $this->getUser();
        $email = $moi->getUserIdentifier();

        $commande->run(new ArrayInput(['--reinitialiser' => true]), new BufferedOutput());

        // Le compte a été recréé (nouvel identifiant) : reconnexion sur le nouveau.
        $compte = $em->getRepository(Utilisateur::class)->findOneBy(['email' => $email]);
        if (!$compte) {
            return $this->redirectToRoute('app_deconnexion');
        }
        $security->login($compte, 'form_login', 'main');
        $this->addFlash('succes', 'La démonstration est remise à zéro : toutes les données d\'origine sont revenues.');

        return $this->redirectToRoute($this->isGranted('ROLE_ENCADREMENT') ? 'app_accueil' : 'app_espace');
    }
}
