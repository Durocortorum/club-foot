<?php

namespace App\Controller;

use App\Entity\Detection;
use App\Entity\JoueurObserve;
use App\Form\DetectionType;
use App\Form\JoueurObserveType;
use App\Repository\DetectionRepository;
use App\Repository\JoueurObserveRepository;
use App\Security\JoueurVoter;
use App\Service\Photos;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Recrutement : journées de détection et joueurs observés dans d'autres clubs.
 * Ouvert à tout éducateur ayant accès à au moins un secteur.
 */
#[Route('/recrutement')]
class RecrutementController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly JoueurVoter $voter,
        private readonly Photos $photos,
    ) {
    }

    #[Route('/detections', name: 'app_detection_index', methods: ['GET'])]
    public function detections(Request $request, DetectionRepository $detections): Response
    {
        $this->verifier();
        $filtres = [
            'q' => trim($request->query->getString('q')) ?: null,
            'annee' => $request->query->getInt('annee') ?: null,
            'generation' => $request->query->getInt('generation') ?: null,
            'retenus' => $request->query->getBoolean('retenus'),
        ];
        $qb = $detections->createQueryBuilder('d')
            ->leftJoin('d.joueur', 'j')->addSelect('j')
            ->orderBy('d.dateDetection', 'DESC')->addOrderBy('d.nom');
        if ($filtres['q']) {
            $qb->andWhere('d.nom LIKE :t OR d.prenom LIKE :t OR d.clubActuel LIKE :t')->setParameter('t', '%' . $filtres['q'] . '%');
        }
        if ($filtres['annee']) {
            $qb->andWhere('d.dateDetection BETWEEN :debut AND :fin')
                ->setParameter('debut', $filtres['annee'] . '-01-01')->setParameter('fin', $filtres['annee'] . '-12-31');
        }
        if ($filtres['generation']) {
            $qb->andWhere('d.dateNaissance BETWEEN :ndebut AND :nfin')
                ->setParameter('ndebut', $filtres['generation'] . '-01-01')->setParameter('nfin', $filtres['generation'] . '-12-31');
        }
        if ($filtres['retenus']) {
            $qb->andWhere('d.retenu = true');
        }

        $toutes = $detections->createQueryBuilder('d')->select('d.dateDetection, d.dateNaissance')->getQuery()->getArrayResult();
        $annees = array_unique(array_filter(array_map(fn (array $d) => $d['dateDetection']?->format('Y'), $toutes)));
        $generations = array_unique(array_filter(array_map(fn (array $d) => $d['dateNaissance']?->format('Y'), $toutes)));
        rsort($annees);
        rsort($generations);

        return $this->render('recrutement/detections.html.twig', [
            'detections' => $qb->getQuery()->getResult(),
            'filtres' => $filtres,
            'annees' => $annees,
            'generations' => $generations,
        ]);
    }

    #[Route('/detections/nouvelle', name: 'app_detection_nouvelle', methods: ['GET', 'POST'])]
    public function nouvelleDetection(Request $request): Response
    {
        $this->verifier();

        return $this->formulaireDetection($request, (new Detection())->setDateDetection(new \DateTimeImmutable('today')), 'Nouvelle détection');
    }

    #[Route('/detections/{id}', name: 'app_detection_voir', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function voirDetection(Detection $detection): Response
    {
        $this->verifier();

        return $this->render('recrutement/detection.html.twig', ['d' => $detection]);
    }

    #[Route('/detections/{id}/modifier', name: 'app_detection_modifier', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function modifierDetection(Request $request, Detection $detection): Response
    {
        $this->verifier();

        return $this->formulaireDetection($request, $detection, 'Modifier ' . $detection->getNomComplet());
    }

    #[Route('/detections/{id}/supprimer', name: 'app_detection_supprimer', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function supprimerDetection(Request $request, Detection $detection): Response
    {
        $this->verifier();
        if ($this->isCsrfTokenValid('supprimer-detection' . $detection->getId(), $request->getPayload()->getString('_token'))) {
            $photo = $detection->getPhoto();
            $this->em->remove($detection);
            $this->em->flush();
            $this->photos->supprimer($photo);
            $this->addFlash('succes', 'Détection supprimée.');
        }

        return $this->redirectToRoute('app_detection_index');
    }

    #[Route('/observes', name: 'app_observe_index', methods: ['GET'])]
    public function observes(Request $request, JoueurObserveRepository $observes): Response
    {
        $this->verifier();
        $texte = trim($request->query->getString('q')) ?: null;
        $qb = $observes->createQueryBuilder('o')->orderBy('o.nom')->addOrderBy('o.prenom');
        if ($texte) {
            $qb->andWhere('o.nom LIKE :t OR o.prenom LIKE :t OR o.clubActuel LIKE :t OR o.poste LIKE :t')->setParameter('t', '%' . $texte . '%');
        }

        return $this->render('recrutement/observes.html.twig', [
            'observes' => $qb->getQuery()->getResult(),
            'filtres' => ['q' => $texte],
        ]);
    }

    #[Route('/observes/nouveau', name: 'app_observe_nouveau', methods: ['GET', 'POST'])]
    public function nouvelObserve(Request $request): Response
    {
        $this->verifier();

        return $this->formulaireObserve($request, (new JoueurObserve())->setDateObservation(new \DateTimeImmutable('today')), 'Nouveau joueur observé');
    }

    #[Route('/observes/{id}', name: 'app_observe_voir', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function voirObserve(JoueurObserve $observe): Response
    {
        $this->verifier();

        return $this->render('recrutement/observe.html.twig', ['o' => $observe, 'grilles' => JoueurObserve::GRILLES]);
    }

    #[Route('/observes/{id}/modifier', name: 'app_observe_modifier', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function modifierObserve(Request $request, JoueurObserve $observe): Response
    {
        $this->verifier();

        return $this->formulaireObserve($request, $observe, 'Modifier ' . $observe->getNomComplet());
    }

    #[Route('/observes/{id}/supprimer', name: 'app_observe_supprimer', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function supprimerObserve(Request $request, JoueurObserve $observe): Response
    {
        $this->verifier();
        if ($this->isCsrfTokenValid('supprimer-observe' . $observe->getId(), $request->getPayload()->getString('_token'))) {
            $this->em->remove($observe);
            $this->em->flush();
            $this->addFlash('succes', 'Joueur observé supprimé.');
        }

        return $this->redirectToRoute('app_observe_index');
    }

    private function formulaireDetection(Request $request, Detection $detection, string $titre): Response
    {
        $form = $this->createForm(DetectionType::class, $detection);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            if ($fichier = $form->get('photo')->getData()) {
                $detection->setPhoto($this->photos->enregistrer($fichier, 'detection', $detection->getPhoto()));
            }
            $this->em->persist($detection);
            $this->em->flush();
            $this->addFlash('succes', 'Détection enregistrée.');

            return $this->redirectToRoute('app_detection_voir', ['id' => $detection->getId()]);
        }

        return $this->render('recrutement/detection_formulaire.html.twig', ['form' => $form, 'd' => $detection, 'titre' => $titre]);
    }

    private function formulaireObserve(Request $request, JoueurObserve $observe, string $titre): Response
    {
        $form = $this->createForm(JoueurObserveType::class, $observe);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->em->persist($observe);
            $this->em->flush();
            $this->addFlash('succes', 'Fiche enregistrée.');

            return $this->redirectToRoute('app_observe_voir', ['id' => $observe->getId()]);
        }

        return $this->render('recrutement/observe_formulaire.html.twig', [
            'form' => $form,
            'o' => $observe,
            'titre' => $titre,
            'grilles' => JoueurObserve::GRILLES,
        ]);
    }

    private function verifier(): void
    {
        if (!$this->voter->secteursModifiables()) {
            throw $this->createAccessDeniedException();
        }
    }
}
