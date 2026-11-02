<?php

namespace App\Controller;

use App\Entity\Categorie;
use App\Entity\Joueur;
use App\Entity\Rencontre;
use App\Entity\Seance;
use App\Entity\SemainePresence;
use App\Form\CategorieType;
use App\Repository\CategorieRepository;
use App\Service\ClubCourant;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Catégories (équipes) du club : ajout, renommage, ordre d'affichage et suppression si elles sont inutilisées.
 */
#[Route('/club/categories')]
#[IsGranted('ROLE_ADMIN')]
class CategorieController extends AbstractController
{
    /** Données qui empêchent de supprimer une catégorie, avec leur libellé. */
    private const UTILISATIONS = [
        Joueur::class => 'joueur',
        Rencontre::class => 'match',
        Seance::class => 'séance',
        SemainePresence::class => 'semaine de présence',
    ];

    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    #[Route('', name: 'app_categorie_index', methods: ['GET'])]
    public function index(CategorieRepository $categories): Response
    {
        $liste = $categories->findBy([], ['ordre' => 'ASC', 'nom' => 'ASC']);

        return $this->render('categorie/index.html.twig', [
            'categories' => $liste,
            'utilisations' => array_combine(array_map(fn (Categorie $c) => $c->getId(), $liste), array_map(fn (Categorie $c) => $this->utilisations($c), $liste)),
            'secteurs' => Categorie::SECTEURS,
        ]);
    }

    #[Route('/nouvelle', name: 'app_categorie_nouvelle', methods: ['GET', 'POST'])]
    public function nouvelle(Request $request, CategorieRepository $categories, ClubCourant $clubCourant): Response
    {
        $dernier = $categories->findOneBy([], ['ordre' => 'DESC']);
        // Club rattaché dès maintenant : le contrôle « catégorie déjà existante » porte sur le club.
        $categorie = (new Categorie())->setOrdre(($dernier?->getOrdre() ?? 0) + 1)->setClub($clubCourant->club());

        return $this->formulaire($request, $categorie, 'Nouvelle catégorie');
    }

    #[Route('/{id}/modifier', name: 'app_categorie_modifier', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function modifier(Request $request, Categorie $categorie): Response
    {
        return $this->formulaire($request, $categorie, 'Modifier la catégorie ' . $categorie);
    }

    #[Route('/{id}/deplacer/{sens}', name: 'app_categorie_deplacer', requirements: ['id' => '\d+', 'sens' => 'monter|descendre'], methods: ['POST'])]
    public function deplacer(Request $request, Categorie $categorie, string $sens, CategorieRepository $categories): Response
    {
        if ($this->isCsrfTokenValid('deplacer-categorie' . $categorie->getId(), $request->getPayload()->getString('_token'))) {
            $liste = $categories->findBy([], ['ordre' => 'ASC', 'nom' => 'ASC']);
            $i = array_search($categorie, $liste, true);
            $j = 'monter' === $sens ? $i - 1 : $i + 1;
            if (isset($liste[$j])) {
                [$liste[$i], $liste[$j]] = [$liste[$j], $liste[$i]];
                foreach ($liste as $rang => $c) {
                    $c->setOrdre($rang + 1);
                }
                $this->em->flush();
            }
        }

        return $this->redirectToRoute('app_categorie_index');
    }

    #[Route('/{id}/supprimer', name: 'app_categorie_supprimer', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function supprimer(Request $request, Categorie $categorie): Response
    {
        if (!$this->isCsrfTokenValid('supprimer-categorie' . $categorie->getId(), $request->getPayload()->getString('_token'))) {
            return $this->redirectToRoute('app_categorie_index');
        }
        if ($utilisee = $this->utilisations($categorie)) {
            $this->addFlash('erreur', \sprintf('« %s » est utilisée (%s) : elle ne peut pas être supprimée. Vous pouvez la renommer.', $categorie, implode(', ', $utilisee)));
        } else {
            $this->em->remove($categorie);
            $this->em->flush();
            $this->addFlash('succes', 'Catégorie supprimée.');
        }

        return $this->redirectToRoute('app_categorie_index');
    }

    private function formulaire(Request $request, Categorie $categorie, string $titre): Response
    {
        $form = $this->createForm(CategorieType::class, $categorie);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->em->persist($categorie);
            $this->em->flush();
            $this->addFlash('succes', 'Catégorie enregistrée.');

            return $this->redirectToRoute('app_categorie_index');
        }

        return $this->render('commun/formulaire.html.twig', [
            'form' => $form,
            'titre' => $titre,
            'retour' => $this->generateUrl('app_categorie_index'),
        ]);
    }

    /** @return list<string> ex. : ["12 joueurs", "3 matchs"] */
    private function utilisations(Categorie $categorie): array
    {
        $resultat = [];
        foreach (self::UTILISATIONS as $classe => $libelle) {
            $n = $this->em->getRepository($classe)->count(['categorie' => $categorie]);
            if ($n) {
                $resultat[] = $n . ' ' . $libelle . ($n > 1 ? 's' : '');
            }
        }

        return $resultat;
    }
}
