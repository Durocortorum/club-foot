<?php

namespace App\Controller;

use App\Entity\Categorie;
use App\Repository\CategorieRepository;
use App\Repository\ConducteurRepository;
use App\Repository\JoueurRepository;
use App\Repository\StageRepository;
use App\Repository\TacheRepository;
use App\Repository\VehiculeRepository;
use App\Security\JoueurVoter;
use App\Service\PremiersPas;
use App\Service\TableauAccueil;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class AccueilController extends AbstractController
{
    #[Route('/', name: 'app_accueil')]
    public function index(
        JoueurRepository $joueurs,
        CategorieRepository $categories,
        JoueurVoter $voter,
        PremiersPas $premiersPas,
        TacheRepository $taches,
        TableauAccueil $tableau,
        StageRepository $stages,
        ConducteurRepository $conducteurs,
        VehiculeRepository $vehicules,
    ): Response {
        if ($this->isGranted('ROLE_MEDICAL_SEUL')) {
            return $this->redirectToRoute($this->isGranted('ROLE_INFIRMERIE_LECTURE') ? 'app_infirmerie_index' : 'app_dossier_medical_index');
        }
        if (!$this->isGranted('ROLE_ENCADREMENT')) {
            return $this->redirectToRoute('app_espace');
        }
        $visibles = $voter->secteursVisibles();
        $donnees = $tableau->pour($visibles);

        // Toutes les catégories des secteurs de l'utilisateur, même sans joueur (club qui démarre).
        $effectifs = array_column($joueurs->compterActifsParCategorie(), 'total', 'id');
        $secteurs = array_fill_keys(array_keys(Categorie::SECTEURS), []);
        $equipes = $categories->findBy(['secteur' => $visibles], ['ordre' => 'ASC', 'nom' => 'ASC']);
        foreach ($equipes as $c) {
            $secteurs[$c->getSecteur()][] = [
                'id' => $c->getId(),
                'nom' => $c->getNom(),
                'feminine' => $c->isFeminine(),
                'total' => (int) ($effectifs[$c->getId()] ?? 0),
                'prochain' => $donnees['prochains'][$c->getId()] ?? null,
            ];
        }
        $totaux = array_map(fn (array $liste) => array_sum(array_column($liste, 'total')), $secteurs);
        $etapes = array_values(array_filter($premiersPas->etapes(), fn (array $e) => !$e['admin'] || $this->isGranted('ROLE_ADMIN')));
        $aujourdhui = new \DateTimeImmutable('today');

        return $this->render('accueil/index.html.twig', $donnees + [
            'secteurs' => array_filter($secteurs),
            'totaux_secteurs' => $totaux,
            'plus_grande' => max([1, ...array_column(array_merge(...array_values($secteurs)), 'total')]),
            'nb_joueurs' => array_sum($totaux),
            'nb_equipes' => \count($equipes),
            'stages' => $stages->entre($aujourdhui, $aujourdhui->modify('+' . TableauAccueil::HORIZON . ' days'), $equipes),
            'libelles' => Categorie::SECTEURS,
            'etapes' => $etapes,
            'etapes_faites' => \count(array_filter($etapes, fn (array $e) => $e['fait'])),
            'mes_taches' => $taches->ouvertesDe($this->getUser(), 5),
            'nb_taches' => $taches->compterOuvertesDe($this->getUser()),
            'taches_club' => $taches->ouvertesDesAutres($this->getUser(), 8),
            // Rappels du transport (permis à contrôler, échéances des véhicules) pour ceux qui organisent les déplacements.
            'permis_a_controler' => $voter->secteursModifiables() || $this->isGranted('ROLE_FINANCES') ? $conducteurs->aControler() : [],
            'vehicules_alertes' => $voter->secteursModifiables() || $this->isGranted('ROLE_FINANCES') ? array_values(array_filter($vehicules->findAll(), fn ($v) => $v->getAlertes())) : [],
            'nb_taches_club' => $taches->compterOuvertes($this->getUser()) - $taches->compterOuvertesDe($this->getUser()),
        ]);
    }
}
