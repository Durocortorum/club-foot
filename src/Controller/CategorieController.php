<?php

namespace App\Controller;

use App\Entity\Categorie;
use App\Entity\Joueur;
use App\Entity\Rencontre;
use App\Entity\ResponsableSecteur;
use App\Entity\Seance;
use App\Entity\SemainePresence;
use App\Entity\Utilisateur;
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
            'responsables_secteur' => $this->em->getRepository(ResponsableSecteur::class)->parSecteur(),
            'comptes_encadrement' => $this->comptesEncadrement(),
        ]);
    }

    /**
     * Responsable de chaque secteur : il voit tout le secteur (le droit du secteur lui est donné s'il ne l'avait pas),
     * modifie les matchs et convocations de toutes ses équipes et choisit qui les modifie.
     */
    #[Route('/responsables-secteur', name: 'app_categorie_responsables_secteur', methods: ['POST'])]
    public function responsablesSecteur(Request $request, ClubCourant $clubCourant): Response
    {
        $p = $request->getPayload();
        if (!$this->isCsrfTokenValid('responsables-secteur', $p->getString('_token'))) {
            return $this->redirectToRoute('app_categorie_index');
        }
        $comptes = [];
        foreach ($this->comptesEncadrement() as $u) {
            $comptes[$u->getId()] = $u;
        }
        $existants = [];
        foreach ($this->em->getRepository(ResponsableSecteur::class)->findAll() as $r) {
            $existants[$r->getSecteur()] = $r;
        }
        $choix = $p->all('responsable');
        $droitsDonnes = [];
        foreach (Categorie::SECTEURS as $secteur => $s) {
            $compte = $comptes[(int) ($choix[$secteur] ?? 0)] ?? null;
            if (!$compte) {
                if (isset($existants[$secteur])) {
                    $this->em->remove($existants[$secteur]);
                }
                continue;
            }
            if (isset($existants[$secteur])) {
                $existants[$secteur]->setUtilisateur($compte);
            } else {
                $this->em->persist((new ResponsableSecteur($secteur, $compte))->setClub($clubCourant->club()));
            }
            // Il voit tout son secteur : droit du secteur donné s'il ne l'avait pas (ni par l'administration).
            if (!\in_array($s['role'], $compte->getRolesAttribues(), true) && !\in_array('ROLE_ADMIN', $compte->getRolesAttribues(), true)) {
                $compte->setRolesAttribues([...$compte->getRolesAttribues(), $s['role']]);
                $droitsDonnes[] = \sprintf('%s %s (%s)', $compte->getPrenom(), $compte->getNom(), $s['libelle']);
            }
        }
        $this->em->flush();
        $this->addFlash('succes', 'Responsables de secteur enregistrés.' . ($droitsDonnes ? ' Droit du secteur donné à : ' . implode(', ', $droitsDonnes) . '.' : ''));

        return $this->redirectToRoute('app_categorie_index', ['_fragment' => 'responsables-secteur']);
    }

    /**
     * Comptes de l'encadrement du club (ni famille, ni médical seul), proposés comme responsables de secteur.
     *
     * @return list<Utilisateur>
     */
    private function comptesEncadrement(): array
    {
        return array_values(array_filter(
            $this->em->getRepository(Utilisateur::class)->findBy([], ['prenom' => 'ASC', 'nom' => 'ASC']),
            fn (Utilisateur $u) => \in_array('ROLE_ENCADREMENT', $u->getRoles(), true)
        ));
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
            if (null === $categorie->getId()) {
                $this->ranger($categorie);
            }
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

    /**
     * Nouvelle catégorie « U.. » rangée à sa place d'âge (U18 entre U17 et U19, avant les seniors) ;
     * les autres (« Féminines », « Loisirs »…) restent à la fin. Les catégories suivantes sont décalées.
     */
    private function ranger(Categorie $nouvelle): void
    {
        $liste = $this->em->getRepository(Categorie::class)->findBy([], ['ordre' => 'ASC', 'nom' => 'ASC']);
        $age = $nouvelle->getAge();
        $place = \count($liste);
        if ($age < 99) {
            foreach ($liste as $i => $c) {
                if ($c->getAge() > $age) {
                    $place = $i;
                    break;
                }
            }
        }
        array_splice($liste, $place, 0, [$nouvelle]);
        foreach ($liste as $rang => $c) {
            $c->setOrdre($rang + 1);
        }
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
