<?php

namespace App\EventSubscriber;

use App\Service\AccesMedical;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Event\ControllerEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Pages médicales (Infirmerie, blessures, contacts médicaux, visite médicale) : sans accès ouvert par code
 * e-mail, on passe d'abord par la page du code, qui ramène ensuite à la page demandée.
 * Un compte sans droit médical n'est pas concerné : la page lui est refusée normalement (403).
 */
class VerrouMedicalSubscriber implements EventSubscriberInterface
{
    private const ROUTES = ['app_infirmerie', 'app_dossier_medical', 'app_blessure', 'app_contact', 'app_mesure'];

    public function __construct(
        private readonly AccesMedical $acces,
        private readonly UrlGeneratorInterface $urls,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::CONTROLLER => 'verifier'];
    }

    public function verifier(ControllerEvent $event): void
    {
        $requete = $event->getRequest();
        $route = (string) $requete->attributes->get('_route');
        if (!$event->isMainRequest() || !$this->estMedicale($route) || !$this->acces->aLeDroit() || $this->acces->estOuvert()) {
            return;
        }
        $url = $this->urls->generate('app_medical_code', ['retour' => $requete->isMethod('GET') ? $requete->getRequestUri() : null]);
        $event->setController(fn () => new RedirectResponse($url));
    }

    private function estMedicale(string $route): bool
    {
        foreach (self::ROUTES as $prefixe) {
            if (str_starts_with($route, $prefixe)) {
                return true;
            }
        }

        return false;
    }
}
