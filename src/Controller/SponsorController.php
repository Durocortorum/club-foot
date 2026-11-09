<?php

namespace App\Controller;

use App\Entity\PartenariatSponsor;
use App\Entity\Rencontre;
use App\Entity\Sponsor;
use App\Form\PartenariatSponsorType;
use App\Form\SponsorType;
use App\Repository\SponsorRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Sponsors et partenaires du club, avec leurs participations saison par saison.
 * Informations financières : réservées à l'administration.
 */
#[Route('/sponsors')]
#[IsGranted('ROLE_ADMIN')]
class SponsorController extends AbstractController
{
    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    #[Route('', name: 'app_sponsor_index', methods: ['GET'])]
    public function index(Request $request, SponsorRepository $sponsors): Response
    {
        $statut = \in_array($s = $request->query->getString('statut', 'actifs'), ['actifs', 'anciens', 'tous'], true) ? $s : 'actifs';
        $texte = trim($request->query->getString('q')) ?: null;
        $qb = $sponsors->createQueryBuilder('s')->leftJoin('s.partenariats', 'p')->addSelect('p')->orderBy('s.nom');
        if ('tous' !== $statut) {
            $qb->andWhere('s.ancien = :ancien')->setParameter('ancien', 'anciens' === $statut);
        }
        if ($texte) {
            $qb->andWhere('s.nom LIKE :t OR s.contact LIKE :t OR s.ville LIKE :t')->setParameter('t', '%' . $texte . '%');
        }
        $liste = $qb->getQuery()->getResult();
        $saison = Rencontre::saisonDe(new \DateTimeImmutable('today'));

        return $this->render('sponsor/index.html.twig', [
            'sponsors' => $liste,
            'filtres' => ['statut' => $statut, 'q' => $texte],
            'saison' => $saison,
            'total_saison' => array_sum(array_map(fn (Sponsor $s) => $s->getTotal($saison), $liste)),
        ]);
    }

    #[Route('/nouveau', name: 'app_sponsor_nouveau', methods: ['GET', 'POST'])]
    public function nouveau(Request $request): Response
    {
        return $this->formulaire($request, new Sponsor(), 'Nouveau sponsor');
    }

    #[Route('/{id}', name: 'app_sponsor_voir', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function voir(Sponsor $sponsor): Response
    {
        $parSaison = [];
        foreach ($sponsor->getPartenariats() as $p) {
            $parSaison[$p->getSaison()][] = $p;
        }

        return $this->render('sponsor/voir.html.twig', ['sponsor' => $sponsor, 'par_saison' => $parSaison]);
    }

    #[Route('/{id}/modifier', name: 'app_sponsor_modifier', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function modifier(Request $request, Sponsor $sponsor): Response
    {
        return $this->formulaire($request, $sponsor, 'Modifier ' . $sponsor);
    }

    #[Route('/{id}/supprimer', name: 'app_sponsor_supprimer', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function supprimer(Request $request, Sponsor $sponsor): Response
    {
        if ($this->isCsrfTokenValid('supprimer-sponsor' . $sponsor->getId(), $request->getPayload()->getString('_token'))) {
            $this->em->remove($sponsor);
            $this->em->flush();
            $this->addFlash('succes', 'Sponsor supprimé.');
        }

        return $this->redirectToRoute('app_sponsor_index');
    }

    #[Route('/{id}/participation', name: 'app_sponsor_participation', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function nouvelleParticipation(Request $request, Sponsor $sponsor): Response
    {
        $p = (new PartenariatSponsor())->setSponsor($sponsor)->setClub($sponsor->getClub())->setSaison(Rencontre::saisonDe(new \DateTimeImmutable('today')));

        return $this->formulaireParticipation($request, $p, 'Nouvelle participation de ' . $sponsor);
    }

    #[Route('/participation/{id}/modifier', name: 'app_sponsor_participation_modifier', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function modifierParticipation(Request $request, PartenariatSponsor $p): Response
    {
        return $this->formulaireParticipation($request, $p, 'Participation ' . $p->getSaison() . ' de ' . $p->getSponsor());
    }

    #[Route('/participation/{id}/supprimer', name: 'app_sponsor_participation_supprimer', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function supprimerParticipation(Request $request, PartenariatSponsor $p): Response
    {
        $sponsor = $p->getSponsor();
        if ($this->isCsrfTokenValid('supprimer-participation' . $p->getId(), $request->getPayload()->getString('_token'))) {
            $sponsor->getPartenariats()->removeElement($p);
            $this->em->remove($p);
            $this->em->flush();
            $this->addFlash('succes', 'Participation supprimée.');
        }

        return $this->redirectToRoute('app_sponsor_voir', ['id' => $sponsor->getId()]);
    }

    private function formulaire(Request $request, Sponsor $sponsor, string $titre): Response
    {
        $form = $this->createForm(SponsorType::class, $sponsor);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $this->em->persist($sponsor);
            $this->em->flush();
            $this->addFlash('succes', 'Fiche enregistrée.');

            return $this->redirectToRoute('app_sponsor_voir', ['id' => $sponsor->getId()]);
        }

        return $this->render('commun/formulaire.html.twig', [
            'form' => $form,
            'titre' => $titre,
            'retour' => $sponsor->getId() ? $this->generateUrl('app_sponsor_voir', ['id' => $sponsor->getId()]) : $this->generateUrl('app_sponsor_index'),
        ] + ($sponsor->getId() ? ['suppression' => [
            'action' => $this->generateUrl('app_sponsor_supprimer', ['id' => $sponsor->getId()]),
            'jeton' => 'supprimer-sponsor' . $sponsor->getId(),
            'question' => 'Supprimer définitivement ' . $sponsor . ' et toutes ses participations ? Pour le garder dans les archives, cochez plutôt « N\'est plus partenaire du club ».',
        ]] : []));
    }

    private function formulaireParticipation(Request $request, PartenariatSponsor $p, string $titre): Response
    {
        $form = $this->createForm(PartenariatSponsorType::class, $p);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $this->em->persist($p);
            $this->em->flush();
            $this->addFlash('succes', 'Participation enregistrée.');

            return $this->redirectToRoute('app_sponsor_voir', ['id' => $p->getSponsor()->getId()]);
        }

        return $this->render('commun/formulaire.html.twig', [
            'form' => $form,
            'titre' => $titre,
            'retour' => $this->generateUrl('app_sponsor_voir', ['id' => $p->getSponsor()->getId()]),
        ] + ($p->getId() ? ['suppression' => [
            'action' => $this->generateUrl('app_sponsor_participation_supprimer', ['id' => $p->getId()]),
            'jeton' => 'supprimer-participation' . $p->getId(),
            'question' => 'Supprimer cette participation ?',
        ]] : []));
    }
}
