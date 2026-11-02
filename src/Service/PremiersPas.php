<?php

namespace App\Service;

use App\Entity\Categorie;
use App\Entity\Club;
use App\Entity\Educateur;
use App\Entity\Joueur;
use App\Entity\Seance;
use App\Entity\Utilisateur;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Étapes de démarrage d'un nouveau club, cochées d'après ses données (affichées sur l'accueil).
 */
class PremiersPas
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly UrlGeneratorInterface $urls,
        private readonly Apparence $apparence,
    ) {
    }

    /** @return list<array{titre: string, texte: string, fait: bool, lien: string, action: string, admin: bool}> */
    public function etapes(): array
    {
        $club = $this->apparence->club();
        $nombre = fn (string $classe) => $this->em->getRepository($classe)->count([]);
        $joueurs = $nombre(Joueur::class);

        return [
            ['titre' => 'Personnaliser le club', 'texte' => 'Votre logo et vos couleurs, pour tous les membres.',
                'fait' => null !== $club->getLogo() || Club::COULEUR !== $club->getCouleur(),
                'lien' => $this->urls->generate('app_club'), 'action' => 'Logo et couleurs', 'admin' => true],
            ['titre' => 'Vérifier vos catégories', 'texte' => 'U7 à Vétérans sont proposées : renommez, ajoutez ou supprimez selon vos équipes.',
                'fait' => $joueurs > 0 || $nombre(Categorie::class) !== array_sum(array_map('count', Categorie::MODELES)),
                'lien' => $this->urls->generate('app_categorie_index'), 'action' => 'Catégories', 'admin' => true],
            ['titre' => 'Ajouter vos éducateurs', 'texte' => 'Leurs fiches (coordonnées, diplômes, équipes encadrées).',
                'fait' => $nombre(Educateur::class) > 0,
                'lien' => $this->urls->generate('app_intervenant_nouveau', ['type' => 'educateurs']), 'action' => 'Nouvel éducateur', 'admin' => false],
            ['titre' => 'Ajouter vos joueurs', 'texte' => 'Un par un, ou tous d\'un coup depuis un fichier Excel (CSV).',
                'fait' => $joueurs > 0,
                'lien' => $this->urls->generate('app_joueur_import'), 'action' => 'Importer des joueurs', 'admin' => false],
            ['titre' => 'Inviter vos collègues', 'texte' => 'Un compte pour chaque éducateur, avec accès à ses seules catégories.',
                'fait' => $nombre(Utilisateur::class) > 1,
                'lien' => $this->urls->generate('app_utilisateur_nouveau'), 'action' => 'Nouveau compte', 'admin' => true],
            ['titre' => 'Préparer une première séance', 'texte' => 'Avec les exercices de la bibliothèque et leurs schémas.',
                'fait' => $nombre(Seance::class) > 0,
                'lien' => $this->urls->generate('app_seance_nouvelle'), 'action' => 'Nouvelle séance', 'admin' => false],
        ];
    }
}
