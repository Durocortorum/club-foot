<?php

namespace App\Controller;

use App\Entity\Categorie;
use App\Entity\Joueur;
use App\Entity\Utilisateur;
use App\Repository\CategorieRepository;
use App\Repository\MesureRepository;
use App\Repository\ParticipationRepository;
use App\Repository\RessentiRepository;
use App\Repository\ResultatTestRepository;
use App\Security\JoueurVoter;
use App\Service\AccesMedical;
use App\Service\BilanJoueur;
use App\Service\CatalogueTests;
use App\Service\Progression;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Suivi de progression : d'un joueur (tests physiques, taille et poids, charge d'entraînement ressentie,
 * statistiques saison après saison) et d'une équipe (derniers tests de chacun et évolution).
 */
class ProgressionController extends AbstractController
{
    #[Route('/joueurs/{id}/progression', name: 'app_progression_joueur', requirements: ['id' => '\d+'], methods: ['GET'])]
    #[IsGranted(JoueurVoter::VOIR, 'joueur')]
    public function joueur(
        Joueur $joueur,
        Progression $progression,
        ResultatTestRepository $resultats,
        CatalogueTests $catalogue,
        MesureRepository $mesures,
        RessentiRepository $ressentis,
        ParticipationRepository $participations,
        BilanJoueur $bilan,
        AccesMedical $medical,
    ): Response {
        $voitTests = $this->isGranted('ROLE_TESTS_PHYSIQUES_LECTURE');
        // Taille et poids : données de santé, droit « Visite médicale » et accès médical ouvert.
        $voitMesures = $this->isGranted('ROLE_VISITE_MEDICALE_LECTURE') && $medical->estOuvert();

        // Charge ressentie par semaine (RPE × minutes), sur les 12 dernières semaines.
        $semaines = [];
        foreach ($ressentis->recents([$joueur], new \DateTimeImmutable('monday this week -11 weeks')) as $r) {
            $lundi = $r->getDate()->modify('monday this week');
            $semaines[$lundi->format('Y-m-d')] ??= ['date' => $lundi, 'valeur' => 0.0];
            $semaines[$lundi->format('Y-m-d')]['valeur'] += $r->getCharge();
        }
        ksort($semaines);

        return $this->render('progression/joueur.html.twig', [
            'joueur' => $joueur,
            'tests' => $voitTests ? $progression->seriesTests($this->resultats($catalogue, $resultats, $joueur)) : null,
            'mesures' => $voitMesures ? $progression->seriesMesures($mesures->findBy(['joueur' => $joueur])) : null,
            'charge' => \count($semaines) > 1 ? $progression->serie('charge', 'Charge d\'entraînement par semaine', 'UA', array_values($semaines)) : null,
            'saisons' => array_filter($bilan->parSaison($joueur, $participations->parJoueur($joueur)), fn (string $s) => 'Total' !== $s, \ARRAY_FILTER_USE_KEY),
            'largeur' => Progression::LARGEUR,
            'hauteur' => Progression::HAUTEUR,
        ]);
    }

    #[Route('/progression', name: 'app_progression_equipe', methods: ['GET'])]
    #[IsGranted('ROLE_TESTS_PHYSIQUES_LECTURE')]
    public function equipe(Request $request, CategorieRepository $categories, JoueurVoter $voter, Progression $progression, CatalogueTests $catalogue): Response
    {
        /** @var Utilisateur $moi */
        $moi = $this->getUser();
        $catalogue->pour($moi->getClub());
        $visibles = $categories->findBy(['secteur' => $voter->secteursVisibles() ?: ['-']], ['ordre' => 'ASC']);
        if (!$visibles) {
            throw $this->createAccessDeniedException();
        }
        $id = $request->query->getString('categorie');
        $categorie = array_values(array_filter($visibles, fn (Categorie $c) => ctype_digit($id) && $c->getId() === (int) $id))[0] ?? $visibles[0];

        return $this->render('progression/equipe.html.twig', [
            'categories' => $visibles,
            'categorie' => $categorie,
            'tableau' => $progression->equipe($categorie),
        ]);
    }

    /** @return list<\App\Entity\ResultatTest> (le catalogue est installé au besoin, avec la reprise des anciens tests) */
    private function resultats(CatalogueTests $catalogue, ResultatTestRepository $resultats, Joueur $joueur): array
    {
        /** @var Utilisateur $moi */
        $moi = $this->getUser();
        $catalogue->pour($moi->getClub());

        return $resultats->findBy(['joueur' => $joueur], ['date' => 'ASC']);
    }
}
