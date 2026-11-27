<?php

namespace App\EventSubscriber;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Équipe choisie gardée d'une page à l'autre : sur les pages filtrées par équipe (matchs, statistiques, présences,
 * entraînements, joueurs…), l'équipe choisie (paramètre « categorie ») est retenue pour la session ; une page ouverte
 * sans équipe précisée reprend celle-là. « Toutes les catégories » (valeur vide) efface le choix. Chaque page vérifie
 * ensuite, comme avant, que l'équipe lui est permise.
 */
class EquipeChoisieSubscriber implements EventSubscriberInterface
{
    private const CLE = 'equipe_choisie';

    /** Pages (routes) dont le filtre « categorie » est l'équipe choisie. */
    private const PAGES = [
        'app_rencontre_index', 'app_statistiques', 'app_championnat', 'app_calendrier',
        'app_presence_index', 'app_planning', 'app_seance_index', 'app_etat_forme', 'app_ressenti', 'app_progression_equipe', 'app_tests',
        'app_joueur_index', 'app_joueur_effectifs', 'app_famille_index',
        'app_infirmerie_index', 'app_dossier_medical_index', 'app_scolarite_index', 'app_finances_cotisations',
    ];

    public static function getSubscribedEvents(): array
    {
        // Après le routage (32) et la session, avant le contrôleur.
        return [KernelEvents::REQUEST => ['retenir', 20]];
    }

    public function retenir(RequestEvent $event): void
    {
        $requete = $event->getRequest();
        if (!$event->isMainRequest() || !$requete->isMethod('GET') || !$requete->hasSession()
            || !\in_array($requete->attributes->get('_route'), self::PAGES, true)) {
            return;
        }
        $session = $requete->getSession();
        if ($requete->query->has('categorie')) {
            $valeur = $requete->query->getString('categorie');
            if (ctype_digit($valeur)) {
                $session->set(self::CLE, (int) $valeur);
            } elseif ('' === $valeur) {
                $session->remove(self::CLE);
            }

            return;
        }
        if ($id = $session->get(self::CLE)) {
            $requete->query->set('categorie', (string) $id);
        }
    }
}
