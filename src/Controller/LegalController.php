<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Pages légales, publiques (connecté ou non) : mentions légales, conditions générales d'utilisation (CGU),
 * conditions générales de vente aux clubs (CGV, avec l'accord de sous-traitance RGPD) et politique de confidentialité.
 * Les informations de l'éditeur sont dans config/packages/legal.yaml.
 */
class LegalController extends AbstractController
{
    public const PAGES = [
        'mentions' => ['/mentions-legales', 'Mentions légales'],
        'cgu' => ['/conditions-utilisation', 'Conditions générales d\'utilisation'],
        'cgv' => ['/conditions-vente', 'Conditions générales de vente'],
        'confidentialite' => ['/confidentialite', 'Politique de confidentialité'],
    ];

    #[Route('/mentions-legales', name: 'app_legal_mentions', methods: ['GET'])]
    public function mentions(): Response
    {
        return $this->render('legal/mentions.html.twig');
    }

    #[Route('/conditions-utilisation', name: 'app_legal_cgu', methods: ['GET'])]
    public function cgu(): Response
    {
        return $this->render('legal/cgu.html.twig');
    }

    #[Route('/conditions-vente', name: 'app_legal_cgv', methods: ['GET'])]
    public function cgv(): Response
    {
        return $this->render('legal/cgv.html.twig');
    }

    #[Route('/confidentialite', name: 'app_legal_confidentialite', methods: ['GET'])]
    public function confidentialite(): Response
    {
        return $this->render('legal/confidentialite.html.twig');
    }
}
