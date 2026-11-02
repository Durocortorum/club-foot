<?php

namespace App\Controller;

use App\Entity\Exercice;
use App\Entity\Seance;
use App\Form\ExerciceType;
use App\Form\SeanceType;
use App\Repository\CategorieRepository;
use App\Repository\SeanceRepository;
use App\Security\ActiviteVoter;
use App\Security\JoueurVoter;
use App\Service\BibliothequeExercices;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Séances d'entraînement et leurs exercices, avec un schéma par exercice.
 */
#[Route('/entrainements')]
class SeanceController extends AbstractController
{
    public function __construct(private readonly JoueurVoter $voter)
    {
    }

    #[Route('', name: 'app_seance_index', methods: ['GET'])]
    public function index(Request $request, SeanceRepository $seances, CategorieRepository $categories): Response
    {
        $secteurs = $this->voter->secteursModifiables();
        $saisons = $seances->saisons();
        $visibles = $categories->findBy(['secteur' => $secteurs], ['ordre' => 'ASC']);
        $saison = $request->query->getString('saison');
        $filtres = [
            'saison' => \in_array($saison, $saisons, true) ? $saison : null,
            // La catégorie mémorisée depuis un autre onglet peut ne pas être proposée ici.
            'categorie' => \in_array($request->query->getInt('categorie'), array_map(fn ($c) => $c->getId(), $visibles), true) ? $request->query->getInt('categorie') : null,
            'q' => trim($request->query->getString('q')) ?: null,
        ];
        $page = max(1, $request->query->getInt('page', 1));
        $resultats = $seances->rechercher($secteurs, $filtres['saison'], $filtres['categorie'], $filtres['q'], $page);

        return $this->render('seance/index.html.twig', [
            'seances' => $resultats,
            'total' => \count($resultats),
            'pages' => max(1, (int) ceil(\count($resultats) / SeanceRepository::PAR_PAGE)),
            'page' => $page,
            'saisons' => $saisons,
            'categories' => $visibles,
            'filtres' => $filtres,
            'peut_creer' => [] !== $secteurs,
        ]);
    }

    #[Route('/nouvelle', name: 'app_seance_nouvelle', methods: ['GET', 'POST'])]
    public function nouvelle(Request $request, EntityManagerInterface $em): Response
    {
        $secteurs = $this->voter->secteursModifiables();
        if (!$secteurs) {
            throw $this->createAccessDeniedException();
        }
        $seance = (new Seance())->setDate(new \DateTimeImmutable('today'));
        $form = $this->createForm(SeanceType::class, $seance, ['secteurs' => $secteurs]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $em->persist($seance);
            $em->flush();
            $this->addFlash('succes', 'Séance créée. Ajoutez maintenant ses exercices.');

            return $this->redirectToRoute('app_seance_voir', ['id' => $seance->getId()]);
        }

        return $this->render('commun/formulaire.html.twig', [
            'form' => $form,
            'titre' => 'Nouvelle séance',
            'retour' => $this->generateUrl('app_seance_index'),
        ]);
    }

    #[Route('/{id}', name: 'app_seance_voir', requirements: ['id' => '\d+'], methods: ['GET'])]
    #[IsGranted(ActiviteVoter::GERER, 'seance')]
    public function voir(Seance $seance, SeanceRepository $seances): Response
    {
        return $this->render('seance/voir.html.twig', [
            'seance' => $seance,
            'destinations' => $seances->destinations($this->voter->secteursModifiables()),
        ]);
    }

    #[Route('/{id}/modifier', name: 'app_seance_modifier', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    #[IsGranted(ActiviteVoter::GERER, 'seance')]
    public function modifier(Request $request, Seance $seance, EntityManagerInterface $em): Response
    {
        $form = $this->createForm(SeanceType::class, $seance, ['secteurs' => $this->voter->secteursModifiables()]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $em->flush();
            $this->addFlash('succes', 'Séance enregistrée.');

            return $this->redirectToRoute('app_seance_voir', ['id' => $seance->getId()]);
        }

        return $this->render('commun/formulaire.html.twig', [
            'form' => $form,
            'titre' => 'Modifier la séance',
            'retour' => $this->generateUrl('app_seance_voir', ['id' => $seance->getId()]),
            'suppression' => [
                'action' => $this->generateUrl('app_seance_supprimer', ['id' => $seance->getId()]),
                'jeton' => 'supprimer-seance' . $seance->getId(),
                'question' => 'Supprimer cette séance et tous ses exercices ?',
            ],
        ]);
    }

    #[Route('/{id}/supprimer', name: 'app_seance_supprimer', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[IsGranted(ActiviteVoter::GERER, 'seance')]
    public function supprimer(Request $request, Seance $seance, EntityManagerInterface $em): Response
    {
        if ($this->isCsrfTokenValid('supprimer-seance' . $seance->getId(), $request->getPayload()->getString('_token'))) {
            $em->remove($seance);
            $em->flush();
            $this->addFlash('succes', 'Séance supprimée.');
        }

        return $this->redirectToRoute('app_seance_index');
    }

    #[Route('/{id}/dupliquer', name: 'app_seance_dupliquer', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[IsGranted(ActiviteVoter::GERER, 'seance')]
    public function dupliquer(Request $request, Seance $seance, EntityManagerInterface $em): Response
    {
        if (!$this->isCsrfTokenValid('dupliquer-seance' . $seance->getId(), $request->getPayload()->getString('_token'))) {
            return $this->redirectToRoute('app_seance_voir', ['id' => $seance->getId()]);
        }
        $copie = (new Seance())
            ->setDate(new \DateTimeImmutable('today'))
            ->setCategorie($seance->getCategorie())
            ->setTheme($seance->getTheme())
            ->setObjectifs($seance->getObjectifs());
        foreach ($seance->getExercices() as $exercice) {
            $copie->addExercice($exercice->copier());
        }
        $em->persist($copie);
        $em->flush();
        $this->addFlash('succes', 'Séance copiée à la date du jour : modifiez la date ou l\'équipe si besoin.');

        return $this->redirectToRoute('app_seance_voir', ['id' => $copie->getId()]);
    }

    #[Route('/{id}/exercices/nouveau', name: 'app_exercice_nouveau', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    #[IsGranted(ActiviteVoter::GERER, 'seance')]
    public function nouvelExercice(Request $request, Seance $seance, EntityManagerInterface $em): Response
    {
        return $this->formulaireExercice($request, $seance, new Exercice(), $em, 'Nouvel exercice');
    }

    #[Route('/{id}/bibliotheque', name: 'app_seance_bibliotheque', requirements: ['id' => '\d+'], methods: ['GET'])]
    #[IsGranted(ActiviteVoter::GERER, 'seance')]
    public function bibliotheque(Seance $seance, BibliothequeExercices $bibliotheque): Response
    {
        return $this->render('seance/bibliotheque.html.twig', [
            'seance' => $seance,
            'groupes' => $bibliotheque->parGroupe($seance->getCategorie()),
        ]);
    }

    #[Route('/{id}/bibliotheque/{cle}', name: 'app_seance_bibliotheque_ajouter', requirements: ['id' => '\d+', 'cle' => '[a-z0-9_]+'], methods: ['POST'])]
    #[IsGranted(ActiviteVoter::GERER, 'seance')]
    public function ajouterDepuisBibliotheque(Request $request, Seance $seance, string $cle, BibliothequeExercices $bibliotheque, EntityManagerInterface $em): Response
    {
        $exercice = $bibliotheque->creer($cle);
        if (!$exercice || !$this->isCsrfTokenValid('bibliotheque' . $seance->getId(), $request->getPayload()->getString('_token'))) {
            return $this->redirectToRoute('app_seance_bibliotheque', ['id' => $seance->getId()]);
        }
        $seance->addExercice($exercice);
        $em->flush();
        $this->addFlash('succes', '« ' . $exercice->getTitre() . ' » ajouté à la séance. Modifiez-le pour l\'adapter à votre groupe.');

        return $this->redirectToRoute('app_seance_voir', ['id' => $seance->getId(), '_fragment' => 'exercice-' . $exercice->getId()]);
    }

    #[Route('/exercices/{id}/modifier', name: 'app_exercice_modifier', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function modifierExercice(Request $request, Exercice $exercice, EntityManagerInterface $em): Response
    {
        $seance = $this->seanceGeree($exercice);

        return $this->formulaireExercice($request, $seance, $exercice, $em, 'Modifier l\'exercice');
    }

    #[Route('/exercices/{id}/supprimer', name: 'app_exercice_supprimer', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function supprimerExercice(Request $request, Exercice $exercice, EntityManagerInterface $em): Response
    {
        $seance = $this->seanceGeree($exercice);
        if ($this->isCsrfTokenValid('supprimer-exercice' . $exercice->getId(), $request->getPayload()->getString('_token'))) {
            $seance->removeExercice($exercice);
            $em->flush();
            $this->addFlash('succes', 'Exercice supprimé.');
        }

        return $this->redirectToRoute('app_seance_voir', ['id' => $seance->getId()]);
    }

    #[Route('/exercices/{id}/deplacer/{sens}', name: 'app_exercice_deplacer', requirements: ['id' => '\d+', 'sens' => 'monter|descendre'], methods: ['POST'])]
    public function deplacerExercice(Request $request, Exercice $exercice, string $sens, EntityManagerInterface $em): Response
    {
        $seance = $this->seanceGeree($exercice);
        if ($this->isCsrfTokenValid('deplacer-exercice' . $exercice->getId(), $request->getPayload()->getString('_token'))) {
            $liste = $seance->getExercices()->getValues();
            $i = array_search($exercice, $liste, true);
            $j = 'monter' === $sens ? $i - 1 : $i + 1;
            if (isset($liste[$j])) {
                [$liste[$i], $liste[$j]] = [$liste[$j], $liste[$i]];
                foreach ($liste as $rang => $e) {
                    $e->setOrdre($rang + 1);
                }
                $em->flush();
            }
        }

        return $this->redirectToRoute('app_seance_voir', ['id' => $seance->getId(), '_fragment' => 'exercice-' . $exercice->getId()]);
    }

    #[Route('/exercices/{id}/copier', name: 'app_exercice_copier', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function copierExercice(Request $request, Exercice $exercice, SeanceRepository $seances, EntityManagerInterface $em): Response
    {
        $source = $this->seanceGeree($exercice);
        $cible = $seances->find($request->getPayload()->getInt('seance'));
        if ($cible && $this->isGranted(ActiviteVoter::GERER, $cible)
            && $this->isCsrfTokenValid('copier-exercice' . $exercice->getId(), $request->getPayload()->getString('_token'))) {
            $cible->addExercice($exercice->copier());
            $em->flush();
            $this->addFlash('succes', 'Exercice copié dans la séance du ' . $cible->getDate()->format('d/m/Y') . '.');

            return $this->redirectToRoute('app_seance_voir', ['id' => $cible->getId()]);
        }

        return $this->redirectToRoute('app_seance_voir', ['id' => $source->getId()]);
    }

    private function formulaireExercice(Request $request, Seance $seance, Exercice $exercice, EntityManagerInterface $em, string $titre): Response
    {
        $form = $this->createForm(ExerciceType::class, $exercice);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $seance->addExercice($exercice);
            $em->flush();
            $this->addFlash('succes', 'Exercice enregistré.');

            return $this->redirectToRoute('app_seance_voir', ['id' => $seance->getId(), '_fragment' => 'exercice-' . $exercice->getId()]);
        }

        return $this->render('seance/exercice.html.twig', [
            'form' => $form,
            'seance' => $seance,
            'exercice' => $exercice,
            'titre' => $titre,
            'terrains' => Exercice::TERRAINS,
        ]);
    }

    private function seanceGeree(Exercice $exercice): Seance
    {
        $seance = $exercice->getSeance();
        $this->denyAccessUnlessGranted(ActiviteVoter::GERER, $seance);

        return $seance;
    }
}
