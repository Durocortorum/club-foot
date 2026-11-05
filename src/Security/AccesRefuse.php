<?php

namespace App\Security;

use App\Entity\Rencontre;
use App\Entity\Utilisateur;
use App\Service\ClubCourant;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Http\Authorization\AccessDeniedHandlerInterface;
use Twig\Environment;

/**
 * Page affichée à un utilisateur connecté qui ouvre une page sans en avoir le droit
 * (compte famille sur une page de l'encadrement, éducateur hors de son secteur...),
 * au lieu de l'erreur 403 brute.
 */
class AccesRefuse implements AccessDeniedHandlerInterface
{
    public function __construct(
        private readonly Security $security,
        private readonly ClubCourant $clubCourant,
        private readonly Environment $twig,
    ) {
    }

    public function handle(Request $request, AccessDeniedException $accessDeniedException): ?Response
    {
        $utilisateur = $this->security->getUser();
        if ($utilisateur instanceof Utilisateur && null === $this->clubCourant->club()) {
            // Refus au niveau du pare-feu : le club n'est pas encore activé (ClubSubscriber), il faut ses couleurs et son logo.
            $this->clubCourant->activer($utilisateur->getClub());
            Rencontre::reconnaitreClub($utilisateur->getClub()->getNom());
        }

        return new Response($this->twig->render('security/acces_refuse.html.twig', ['utilisateur' => $utilisateur]), Response::HTTP_FORBIDDEN);
    }
}
