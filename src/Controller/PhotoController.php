<?php

namespace App\Controller;

use App\Service\Photos;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Sert les photos des fiches aux seuls utilisateurs connectés (voir access_control).
 */
class PhotoController extends AbstractController
{
    // Pas d'extension dans l'adresse : le serveur intégré de PHP (php -S) servirait
    // sinon « .jpg » comme un fichier statique sans passer par l'application.
    #[Route('/photos/{nom}', name: 'app_photo', requirements: ['nom' => '[a-z]+-[a-z0-9]+'], methods: ['GET'])]
    public function photo(string $nom, Photos $photos, Request $request): Response
    {
        $chemin = $photos->chemin($nom . '.jpg') ?? throw $this->createNotFoundException();

        $reponse = new BinaryFileResponse($chemin);
        // Cache du navigateur uniquement : jamais dans un cache partagé.
        $reponse->setPrivate();
        $reponse->setMaxAge(86400);
        $reponse->setAutoLastModified();
        $reponse->isNotModified($request);

        return $reponse;
    }
}
