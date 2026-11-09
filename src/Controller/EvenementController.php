<?php

namespace App\Controller;

use App\Entity\Categorie;
use App\Entity\Evenement;
use App\Form\EvenementType;
use App\Repository\CategorieRepository;
use App\Repository\EvenementRepository;
use App\Security\JoueurVoter;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Événements extra-sportifs du club. Toute l'encadrement les voit ; un éducateur crée ceux de ses équipes,
 * l'administrateur aussi ceux de tout le club.
 */
#[Route('/evenements')]
class EvenementController extends AbstractController
{
    public function __construct(
        private readonly JoueurVoter $voter,
        private readonly CategorieRepository $categories,
        private readonly EntityManagerInterface $em,
    ) {
    }

    #[Route('', name: 'app_evenement_index', methods: ['GET'])]
    public function index(Request $request, EvenementRepository $evenements): Response
    {
        $passes = 'passes' === $request->query->getString('periode');
        $aujourdhui = new \DateTimeImmutable('today');
        $liste = $passes
            ? array_reverse($evenements->entre(new \DateTimeImmutable('2000-01-01'), $aujourdhui->modify('-1 day')))
            : $evenements->entre($aujourdhui, new \DateTimeImmutable('2100-01-01'));
        if ($passes) {
            // Passés : terminés avant aujourd'hui (un événement en cours reste dans « À venir »).
            $liste = array_values(array_filter($liste, fn (Evenement $e) => $e->getDernierJour() < $aujourdhui));
        }

        return $this->render('evenement/index.html.twig', [
            'evenements' => $liste,
            'passes' => $passes,
            'peut_creer' => [] !== $this->voter->secteursModifiables(),
            'modifiables' => array_map(fn (Evenement $e) => $e->getId(), array_filter($liste, fn (Evenement $e) => $this->peutModifier($e))),
        ]);
    }

    #[Route('/nouveau', name: 'app_evenement_nouveau', methods: ['GET', 'POST'])]
    public function nouveau(Request $request): Response
    {
        if (!$this->voter->secteursModifiables()) {
            throw $this->createAccessDeniedException();
        }
        $debut = \DateTimeImmutable::createFromFormat('!Y-m-d', $request->query->getString('date')) ?: new \DateTimeImmutable('today');
        $evenement = (new Evenement())->setDebut($debut->setTime(19, 0));
        if ($c = $this->categories->find($request->query->getInt('categorie'))) {
            if (\in_array($c, $this->modifiables(), true)) {
                $evenement->addCategory($c);
            }
        }

        return $this->formulaire($request, $evenement, 'Nouvel événement');
    }

    #[Route('/{id}/modifier', name: 'app_evenement_modifier', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function modifier(Request $request, Evenement $evenement): Response
    {
        $this->verifierModifiable($evenement);

        return $this->formulaire($request, $evenement, 'Modifier « ' . $evenement . ' »');
    }

    #[Route('/{id}/supprimer', name: 'app_evenement_supprimer', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function supprimer(Request $request, Evenement $evenement): Response
    {
        $this->verifierModifiable($evenement);
        if ($this->isCsrfTokenValid('supprimer-evenement' . $evenement->getId(), $request->getPayload()->getString('_token'))) {
            $this->em->remove($evenement);
            $this->em->flush();
            $this->addFlash('succes', 'Événement supprimé.');
        }

        return $this->redirectToRoute('app_evenement_index');
    }

    private function formulaire(Request $request, Evenement $evenement, string $titre): Response
    {
        $admin = $this->isGranted('ROLE_ADMIN');
        // Équipes proposées : celles de l'utilisateur, plus celles déjà cochées par un autre (gardées telles quelles).
        $proposees = [];
        foreach (array_merge($this->modifiables(), $evenement->getCategories()->toArray()) as $c) {
            $proposees[$c->getId()] = $c;
        }
        $proposees = array_values($proposees);
        usort($proposees, fn (Categorie $a, Categorie $b) => $a->getOrdre() <=> $b->getOrdre());
        $form = $this->createForm(EvenementType::class, $evenement, ['categories' => $proposees, 'tout_le_club' => $admin]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && !$admin && $evenement->isToutLeClub()) {
            $form->get('categories')->addError(new FormError('Cochez au moins une équipe : un événement pour tout le club est créé par l\'administrateur.'));
        }
        if ($form->isSubmitted() && $form->isValid()) {
            $this->em->persist($evenement);
            $this->em->flush();
            $this->addFlash('succes', 'Événement enregistré.');

            return $this->redirectToRoute('app_evenement_index');
        }

        return $this->render('commun/formulaire.html.twig', [
            'form' => $form,
            'titre' => $titre,
            'retour' => $this->generateUrl('app_evenement_index'),
        ] + ($evenement->getId() ? ['suppression' => [
            'action' => $this->generateUrl('app_evenement_supprimer', ['id' => $evenement->getId()]),
            'jeton' => 'supprimer-evenement' . $evenement->getId(),
            'question' => 'Supprimer l\'événement « ' . $evenement . ' » ?',
        ]] : []));
    }

    /** @return list<Categorie> */
    private function modifiables(): array
    {
        return $this->categories->findBy(['secteur' => $this->voter->secteursModifiables() ?: ['-']], ['ordre' => 'ASC']);
    }

    /** L'administrateur modifie tout ; un éducateur, les événements dont toutes les équipes sont les siennes. */
    private function peutModifier(Evenement $evenement): bool
    {
        if ($this->isGranted('ROLE_ADMIN')) {
            return true;
        }
        $miennes = array_map(fn (Categorie $c) => $c->getId(), $this->modifiables());

        return !$evenement->isToutLeClub() && !array_diff(array_map(fn (Categorie $c) => $c->getId(), $evenement->getCategories()->toArray()), $miennes);
    }

    private function verifierModifiable(Evenement $evenement): void
    {
        if (!$this->peutModifier($evenement)) {
            throw $this->createAccessDeniedException();
        }
    }
}
