<?php

namespace App\Controller;

use App\Entity\Rencontre;
use App\Entity\Sanction;
use App\Form\SanctionType;
use App\Repository\JoueurRepository;
use App\Repository\SanctionRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Discipline de tous les joueurs, mineurs et majeurs : incidents, sanctions sportives ou scolaires, amendes.
 * Droit « Discipline » (voir / modifier), distinct de la Scolarité.
 */
#[Route('/discipline')]
#[IsGranted('ROLE_DISCIPLINE_LECTURE')]
class DisciplineController extends AbstractController
{
    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    #[Route('', name: 'app_discipline_index', methods: ['GET'])]
    public function index(Request $request, SanctionRepository $sanctions): Response
    {
        $toutes = $sanctions->createQueryBuilder('s')
            ->addSelect('j', 'c')->join('s.joueur', 'j')->leftJoin('j.categorie', 'c')
            ->orderBy('s.date', 'DESC')
            ->getQuery()->getResult();
        // Amendes : infligées et encaissées pendant la saison en cours, reste à payer (toutes saisons).
        $saison = Rencontre::saisonDe(new \DateTimeImmutable('today'));
        [$debut, $fin] = Rencontre::bornesSaison($saison);
        $dansSaison = fn (?\DateTimeImmutable $d) => null !== $d && $d >= $debut && $d <= $fin;
        $amendes = [
            'saison' => $saison,
            'infligees' => array_sum(array_map(fn (Sanction $s) => $dansSaison($s->getDate()) ? (float) $s->getAmende() : 0, $toutes)),
            'encaissees' => array_sum(array_map(fn (Sanction $s) => $dansSaison($s->getAmendePayeeLe()) ? (float) $s->getAmende() : 0, $toutes)),
            'a_payer' => array_sum(array_map(fn (Sanction $s) => $s->isAmendeAPayer() ? $s->getAmende() : 0, $toutes)),
            'nb_a_payer' => \count(array_filter($toutes, fn (Sanction $s) => $s->isAmendeAPayer())),
        ];
        $filtre = 'a_payer' === $request->query->getString('amendes') ? 'a_payer' : null;

        return $this->render('discipline/index.html.twig', [
            'sanctions' => $filtre ? array_values(array_filter($toutes, fn (Sanction $s) => $s->isAmendeAPayer())) : $toutes,
            'amendes' => $amendes,
            'filtre' => $filtre,
        ]);
    }

    /** L'amende est payée aujourd'hui (bouton de la page Discipline). */
    #[IsGranted('ROLE_DISCIPLINE')]
    #[Route('/{id}/amende-payee', name: 'app_sanction_amende_payee', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function amendePayee(Request $request, Sanction $sanction): Response
    {
        if ($sanction->isAmendeAPayer() && $this->isCsrfTokenValid('amende-payee' . $sanction->getId(), $request->getPayload()->getString('_token'))) {
            $sanction->setAmendePayeeLe(new \DateTimeImmutable('today'));
            $this->em->flush();
            $this->addFlash('succes', \sprintf('Amende de %s notée payée.', $sanction->getJoueur()->getNomComplet()));
        }

        return $this->redirect($this->generateUrl('app_discipline_index', array_filter(['amendes' => $request->getPayload()->getString('filtre') ?: null])));
    }

    #[IsGranted('ROLE_DISCIPLINE')]
    #[Route('/nouvelle', name: 'app_sanction_nouvelle', methods: ['GET', 'POST'])]
    public function nouvelle(Request $request, JoueurRepository $joueurs): Response
    {
        $sanction = (new Sanction())->setDate(new \DateTimeImmutable('today'));
        $joueur = $request->query->getInt('joueur') ? $joueurs->find($request->query->getInt('joueur')) : null;
        $sanction->setJoueur($joueur);

        return $this->formulaire($request, $sanction, 'Nouvel incident de discipline', null !== $joueur);
    }

    #[IsGranted('ROLE_DISCIPLINE')]
    #[Route('/{id}/modifier', name: 'app_sanction_modifier', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function modifier(Request $request, Sanction $sanction): Response
    {
        return $this->formulaire($request, $sanction, 'Discipline – ' . $sanction->getJoueur()->getNomComplet(), true);
    }

    #[IsGranted('ROLE_DISCIPLINE')]
    #[Route('/{id}/supprimer', name: 'app_sanction_supprimer', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function supprimer(Request $request, Sanction $sanction): Response
    {
        if ($this->isCsrfTokenValid('supprimer-sanction' . $sanction->getId(), $request->getPayload()->getString('_token'))) {
            $this->em->remove($sanction);
            $this->em->flush();
            $this->addFlash('succes', 'Incident supprimé.');
        }

        return $this->redirectToRoute('app_discipline_index');
    }

    private function formulaire(Request $request, Sanction $sanction, string $titre, bool $joueurFixe): Response
    {
        $form = $this->createForm(SanctionType::class, $sanction, ['joueur_fixe' => $joueurFixe]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->em->persist($sanction);
            $this->em->flush();
            $this->addFlash('succes', 'Incident enregistré.');

            return $this->redirectToRoute('app_joueur_voir', ['id' => $sanction->getJoueur()->getId(), '_fragment' => 'scolarite']);
        }

        $vue = ['form' => $form, 'titre' => $titre, 'retour' => $this->generateUrl('app_discipline_index')];
        if ($sanction->getId()) {
            $vue['suppression'] = [
                'action' => $this->generateUrl('app_sanction_supprimer', ['id' => $sanction->getId()]),
                'jeton' => 'supprimer-sanction' . $sanction->getId(),
                'question' => 'Supprimer cet incident ?',
            ];
        }

        return $this->render('commun/formulaire.html.twig', $vue);
    }
}
