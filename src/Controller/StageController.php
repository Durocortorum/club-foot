<?php

namespace App\Controller;

use App\Entity\Categorie;
use App\Entity\Joueur;
use App\Entity\Stage;
use App\Form\StageType;
use App\Repository\CategorieRepository;
use App\Repository\JoueurRepository;
use App\Repository\StageRepository;
use App\Security\JoueurVoter;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Stages du club. Tout l'encadrement les voit ; un éducateur crée et gère ceux de ses équipes,
 * l'administrateur aussi ceux de tout le club (mêmes règles que les événements).
 */
#[Route('/stages')]
class StageController extends AbstractController
{
    public function __construct(
        private readonly JoueurVoter $voter,
        private readonly CategorieRepository $categories,
        private readonly EntityManagerInterface $em,
    ) {
    }

    #[Route('', name: 'app_stage_index', methods: ['GET'])]
    public function index(Request $request, StageRepository $stages): Response
    {
        $passes = 'passes' === $request->query->getString('periode');
        $aujourdhui = new \DateTimeImmutable('today');
        $liste = $passes
            ? array_reverse(array_values(array_filter(
                $stages->entre(new \DateTimeImmutable('2000-01-01'), $aujourdhui->modify('-1 day')),
                fn (Stage $s) => $s->getDernierJour() < $aujourdhui,
            )))
            : $stages->entre($aujourdhui, new \DateTimeImmutable('2100-01-01'));

        return $this->render('stage/index.html.twig', [
            'stages' => $liste,
            'passes' => $passes,
            'peut_creer' => [] !== $this->voter->secteursModifiables(),
        ]);
    }

    #[Route('/nouveau', name: 'app_stage_nouveau', methods: ['GET', 'POST'])]
    public function nouveau(Request $request): Response
    {
        if (!$this->voter->secteursModifiables()) {
            throw $this->createAccessDeniedException();
        }
        $debut = \DateTimeImmutable::createFromFormat('!Y-m-d', $request->query->getString('date')) ?: new \DateTimeImmutable('today');
        $stage = (new Stage())->setDebut($debut);
        if (($id = $request->query->getString('categorie')) && ctype_digit($id) && ($c = $this->categories->find((int) $id)) && \in_array($c, $this->modifiables(), true)) {
            $stage->addCategory($c);
        }

        return $this->formulaire($request, $stage, 'Nouveau stage');
    }

    #[Route('/{id}', name: 'app_stage_voir', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function voir(Stage $stage, JoueurRepository $joueurs): Response
    {
        $peutModifier = $this->peutModifier($stage);
        // Joueurs qu'on peut encore inscrire : ceux des équipes concernées (toutes si tout le club), par catégorie.
        $aInscrire = [];
        if ($peutModifier) {
            $qb = $joueurs->createQueryBuilder('j')->leftJoin('j.categorie', 'c')->addSelect('c')
                ->where('j.ancien = false')->orderBy('c.ordre')->addOrderBy('j.nom')->addOrderBy('j.prenom');
            if (!$stage->isToutLeClub()) {
                $qb->andWhere('j.categorie IN (:categories)')->setParameter('categories', $stage->getCategories());
            }
            foreach ($qb->getQuery()->getResult() as $j) {
                if (!$stage->inscription($j)) {
                    $aInscrire[$j->getCategorie()?->getNom() ?? 'Sans catégorie'][] = $j;
                }
            }
        }
        $inscriptions = $stage->getInscriptions()->toArray();
        usort($inscriptions, fn ($a, $b) => [$a->getJoueur()->getCategorie()?->getOrdre(), $a->getJoueur()->getNom()] <=> [$b->getJoueur()->getCategorie()?->getOrdre(), $b->getJoueur()->getNom()]);

        return $this->render('stage/voir.html.twig', [
            's' => $stage,
            'inscriptions' => $inscriptions,
            'a_inscrire' => $aInscrire,
            'peut_modifier' => $peutModifier,
        ]);
    }

    #[Route('/{id}/modifier', name: 'app_stage_modifier', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function modifier(Request $request, Stage $stage): Response
    {
        $this->verifierModifiable($stage);

        return $this->formulaire($request, $stage, 'Modifier « ' . $stage . ' »');
    }

    #[Route('/{id}/supprimer', name: 'app_stage_supprimer', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function supprimer(Request $request, Stage $stage): Response
    {
        $this->verifierModifiable($stage);
        if ($this->isCsrfTokenValid('supprimer-stage' . $stage->getId(), $request->getPayload()->getString('_token'))) {
            $this->em->remove($stage);
            $this->em->flush();
            $this->addFlash('succes', 'Stage supprimé.');
        }

        return $this->redirectToRoute('app_stage_index');
    }

    /** Inscrire les joueurs cochés. */
    #[Route('/{id}/inscrire', name: 'app_stage_inscrire', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function inscrire(Request $request, Stage $stage, JoueurRepository $joueurs): Response
    {
        $this->verifierModifiable($stage);
        if ($this->isCsrfTokenValid('inscrire-stage' . $stage->getId(), $request->getPayload()->getString('_token'))) {
            $ids = array_map('intval', $request->getPayload()->all('joueurs'));
            $ajoutes = 0;
            foreach ($ids ? $joueurs->findBy(['id' => $ids, 'ancien' => false]) : [] as $j) {
                if (!$stage->inscription($j) && $stage->concerne(array_filter([$j->getCategorie()]))) {
                    $this->em->persist($stage->inscrire($j));
                    ++$ajoutes;
                }
            }
            $this->em->flush();
            $message = $ajoutes ? $ajoutes . ' ' . ($ajoutes > 1 ? 'joueurs inscrits' : 'joueur inscrit') . '.' : 'Aucun joueur coché.';
            if ($stage->getPlaces() && $stage->getInscriptions()->count() > $stage->getPlaces()) {
                $message .= ' Attention : ' . $stage->getInscriptions()->count() . ' inscrits pour ' . $stage->getPlaces() . ' places.';
            }
            $this->addFlash($ajoutes ? 'succes' : 'info', $message);
        }

        return $this->redirectToRoute('app_stage_voir', ['id' => $stage->getId(), '_fragment' => 'participants']);
    }

    /** Payé / pas payé, ou retirer un joueur. */
    #[Route('/{id}/joueur/{joueur}/{action}', name: 'app_stage_inscription', requirements: ['id' => '\d+', 'joueur' => '\d+', 'action' => 'paye|non-paye|retirer'], methods: ['POST'])]
    public function inscription(Request $request, Stage $stage, Joueur $joueur, string $action): Response
    {
        $this->verifierModifiable($stage);
        $inscription = $stage->inscription($joueur);
        if ($inscription && $this->isCsrfTokenValid('inscription-stage' . $stage->getId() . '-' . $joueur->getId(), $request->getPayload()->getString('_token'))) {
            match ($action) {
                'paye' => $inscription->setPaye(true),
                'non-paye' => $inscription->setPaye(false),
                'retirer' => $stage->desinscrire($joueur),
            };
            $this->em->flush();
            if ('retirer' === $action) {
                $this->addFlash('succes', $joueur->getPrenom() . ' ' . $joueur->getNom() . ' est retiré(e) du stage.');
            }
        }

        return $this->redirectToRoute('app_stage_voir', ['id' => $stage->getId(), '_fragment' => 'participants']);
    }

    private function formulaire(Request $request, Stage $stage, string $titre): Response
    {
        $admin = $this->isGranted('ROLE_ADMIN');
        $proposees = [];
        foreach (array_merge($this->modifiables(), $stage->getCategories()->toArray()) as $c) {
            $proposees[$c->getId()] = $c;
        }
        $proposees = array_values($proposees);
        usort($proposees, fn (Categorie $a, Categorie $b) => $a->getOrdre() <=> $b->getOrdre());
        $form = $this->createForm(StageType::class, $stage, ['categories' => $proposees, 'tout_le_club' => $admin]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && !$admin && $stage->isToutLeClub()) {
            $form->get('categories')->addError(new FormError('Cochez au moins une équipe : un stage pour tout le club est créé par l\'administrateur.'));
        }
        if ($form->isSubmitted() && $form->isValid()) {
            $this->em->persist($stage);
            $this->em->flush();
            $this->addFlash('succes', 'Stage enregistré.');

            return $this->redirectToRoute('app_stage_voir', ['id' => $stage->getId()]);
        }

        return $this->render('commun/formulaire.html.twig', [
            'form' => $form,
            'titre' => $titre,
            'retour' => $stage->getId() ? $this->generateUrl('app_stage_voir', ['id' => $stage->getId()]) : $this->generateUrl('app_stage_index'),
        ] + ($stage->getId() ? ['suppression' => [
            'action' => $this->generateUrl('app_stage_supprimer', ['id' => $stage->getId()]),
            'jeton' => 'supprimer-stage' . $stage->getId(),
            'question' => 'Supprimer le stage « ' . $stage . ' » et ses inscriptions ?',
        ]] : []));
    }

    /** @return list<Categorie> */
    private function modifiables(): array
    {
        return $this->categories->findBy(['secteur' => $this->voter->secteursModifiables() ?: ['-']], ['ordre' => 'ASC']);
    }

    /** L'administrateur gère tout ; un éducateur, les stages dont toutes les équipes sont les siennes. */
    private function peutModifier(Stage $stage): bool
    {
        if ($this->isGranted('ROLE_ADMIN')) {
            return true;
        }
        $miennes = array_map(fn (Categorie $c) => $c->getId(), $this->modifiables());

        return !$stage->isToutLeClub() && !array_diff(array_map(fn (Categorie $c) => $c->getId(), $stage->getCategories()->toArray()), $miennes);
    }

    private function verifierModifiable(Stage $stage): void
    {
        if (!$this->peutModifier($stage)) {
            throw $this->createAccessDeniedException();
        }
    }
}
