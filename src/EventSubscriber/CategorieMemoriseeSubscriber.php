<?php

namespace App\EventSubscriber;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Retient la catégorie choisie dans un filtre et la réapplique sur les autres onglets.
 *
 * Un paramètre « categorie » présent dans l'URL (même vide = « Toutes ») remplace le choix mémorisé ;
 * absent, c'est le choix mémorisé en session qui est injecté dans la requête.
 */
class CategorieMemoriseeSubscriber implements EventSubscriberInterface
{
    private const CLE = 'filtre_categorie';

    private const ROUTES = [
        'app_joueur_index',
        'app_presence_index',
        'app_rencontre_index',
        'app_statistiques',
        'app_infirmerie_index',
        'app_scolarite_index',
        'app_seance_index',
    ];

    public static function getSubscribedEvents(): array
    {
        // Après le RouterListener (priorité 32), pour connaître la route.
        return [KernelEvents::REQUEST => ['memoriser', 0]];
    }

    public function memoriser(RequestEvent $event): void
    {
        $request = $event->getRequest();
        if (!$event->isMainRequest() || !$request->hasSession()
            || !\in_array($request->attributes->get('_route'), self::ROUTES, true)) {
            return;
        }

        $session = $request->getSession();
        if ($request->query->has('categorie')) {
            $categorie = $request->query->getInt('categorie');
            $categorie ? $session->set(self::CLE, $categorie) : $session->remove(self::CLE);
        } elseif ($session->has(self::CLE)) {
            $request->query->set('categorie', (string) $session->get(self::CLE));
        }
    }
}
