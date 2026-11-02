<?php

namespace App\EventSubscriber;

use App\Entity\Rencontre;
use App\Entity\Utilisateur;
use App\Service\ClubCourant;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Dès que l'utilisateur est connu (juste après le pare-feu), limite toute l'application
 * aux données de son club, et indique aux matchs le nom du club pour reconnaître son équipe.
 */
class ClubSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly Security $security,
        private readonly ClubCourant $clubCourant,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        // Le pare-feu (priorité 8) a déjà chargé l'utilisateur.
        return [KernelEvents::REQUEST => ['activer', 7]];
    }

    public function activer(RequestEvent $event): void
    {
        $utilisateur = $this->security->getUser();
        if (!$event->isMainRequest() || !$utilisateur instanceof Utilisateur) {
            return;
        }
        $club = $utilisateur->getClub();
        $this->clubCourant->activer($club);
        Rencontre::reconnaitreClub($club->getNom());
    }
}
