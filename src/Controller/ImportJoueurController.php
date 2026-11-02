<?php

namespace App\Controller;

use App\Entity\Categorie;
use App\Repository\CategorieRepository;
use App\Security\JoueurVoter;
use App\Service\ImportJoueurs;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Import de joueurs depuis un fichier CSV : envoi, aperçu ligne par ligne, puis confirmation.
 * L'aperçu est gardé en session entre les deux étapes.
 */
#[Route('/joueurs/import')]
class ImportJoueurController extends AbstractController
{
    private const SESSION = 'import_joueurs';

    public function __construct(
        private readonly JoueurVoter $voter,
        private readonly CategorieRepository $categories,
    ) {
    }

    #[Route('', name: 'app_joueur_import', methods: ['GET', 'POST'])]
    public function envoyer(Request $request, ImportJoueurs $import): Response
    {
        $autorisees = $this->categoriesAutorisees();
        if (!$autorisees) {
            throw $this->createAccessDeniedException();
        }
        $vue = ['categories' => $autorisees, 'modele' => ImportJoueurs::MODELE, 'max' => ImportJoueurs::MAX_LIGNES];

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('import-joueurs', $request->getPayload()->getString('_token'))) {
                throw $this->createAccessDeniedException();
            }
            $fichier = $request->files->get('fichier');
            $parDefaut = $autorisees[$request->getPayload()->getInt('categorie')] ?? null;
            if (!$fichier || !$fichier->isValid() || $fichier->getSize() > 2 * 1024 * 1024) {
                $this->addFlash('erreur', 'Choisissez un fichier CSV de 2 Mo au plus.');

                return $this->render('joueur/import.html.twig', $vue);
            }
            $parNom = [];
            foreach ($autorisees as $c) {
                $parNom[$c->getNom()] = $c;
            }
            $analyse = $import->analyser((string) file_get_contents($fichier->getPathname()), $parNom, $parDefaut);
            if ($analyse['erreur']) {
                $this->addFlash('erreur', $analyse['erreur']);

                return $this->render('joueur/import.html.twig', $vue);
            }
            $request->getSession()->set(self::SESSION, $analyse['lignes']);

            return $this->render('joueur/import.html.twig', $vue + [
                'analyse' => $analyse,
                'a_importer' => \count(array_filter($analyse['lignes'], fn (array $l) => !$l['erreurs'] && !$l['doublon'])),
            ]);
        }

        return $this->render('joueur/import.html.twig', $vue);
    }

    #[Route('/confirmer', name: 'app_joueur_import_confirmer', methods: ['POST'])]
    public function confirmer(Request $request, ImportJoueurs $import, EntityManagerInterface $em): Response
    {
        $lignes = $request->getSession()->remove(self::SESSION);
        if (!$lignes || !$this->isCsrfTokenValid('import-joueurs-confirmer', $request->getPayload()->getString('_token'))) {
            return $this->redirectToRoute('app_joueur_import');
        }
        // Les catégories sont recontrôlées : seules celles des secteurs de l'utilisateur sont acceptées.
        $joueurs = $import->creer($lignes, $this->categoriesAutorisees());
        foreach ($joueurs as $joueur) {
            if (null === $joueur->getCategorie()) {
                continue;
            }
            $em->persist($joueur);
        }
        $em->flush();
        $this->addFlash('succes', \sprintf('%d joueur%s importé%s.', \count($joueurs), \count($joueurs) > 1 ? 's' : '', \count($joueurs) > 1 ? 's' : ''));

        return $this->redirectToRoute('app_joueur_index', ['categorie' => '', 'statut' => 'actifs']);
    }

    #[Route('/modele.csv', name: 'app_joueur_import_modele', methods: ['GET'])]
    public function modele(): Response
    {
        $exemple = ['DUPONT', 'Lucas', '14/03/2012', $this->categoriesAutorisees() ? array_values($this->categoriesAutorisees())[0]->getNom() : 'U15',
            'Milieu', 'Droitier', '', '12 rue des Lilas', '51100', 'Reims', '06 12 34 56 78', '', 'parents.dupont@exemple.fr', '06 98 76 54 32', ''];
        $ligne = fn (array $cellules) => implode(';', array_map(fn ($c) => '"' . str_replace('"', '""', $c) . '"', $cellules));
        // BOM UTF-8 : Excel ouvre ainsi correctement les accents.
        $contenu = "\xEF\xBB\xBF" . $ligne(ImportJoueurs::MODELE) . "\r\n" . $ligne($exemple) . "\r\n";

        return new Response($contenu, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="modele-import-joueurs.csv"',
        ]);
    }

    /** @return array<int, Categorie> catégories des secteurs que l'utilisateur peut modifier, par id */
    private function categoriesAutorisees(): array
    {
        $resultat = [];
        foreach ($this->categories->findBy(['secteur' => $this->voter->secteursModifiables()], ['ordre' => 'ASC']) as $c) {
            $resultat[$c->getId()] = $c;
        }

        return $resultat;
    }
}
