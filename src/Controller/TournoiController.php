<?php

namespace App\Controller;

use App\Entity\Categorie;
use App\Entity\Rencontre;
use App\Entity\Tournoi;
use App\Form\TournoiType;
use App\Repository\CategorieRepository;
use App\Repository\TournoiRepository;
use App\Security\ActiviteVoter;
use App\Security\JoueurVoter;
use App\Service\Apparence;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Tournois : fiche (lieu, dates, transport, coût, résultat) et matchs du tournoi, qui sont des rencontres
 * « Tournoi » (score, feuille de match et convocation comme les autres matchs). Tout l'encadrement les voit ;
 * l'éducateur gère ceux de ses équipes, l'administrateur tous.
 */
#[Route('/tournois')]
class TournoiController extends AbstractController
{
    public function __construct(
        private readonly JoueurVoter $voter,
        private readonly CategorieRepository $categories,
        private readonly EntityManagerInterface $em,
    ) {
    }

    #[Route('', name: 'app_tournoi_index', methods: ['GET'])]
    public function index(Request $request, TournoiRepository $tournois): Response
    {
        $passes = 'passes' === $request->query->getString('periode');
        $aujourdhui = new \DateTimeImmutable('today');
        $liste = $passes
            ? array_reverse(array_values(array_filter($tournois->entre(new \DateTimeImmutable('2000-01-01'), $aujourdhui->modify('-1 day')), fn (Tournoi $t) => $t->getDernierJour() < $aujourdhui)))
            : $tournois->entre($aujourdhui, new \DateTimeImmutable('2100-01-01'));

        return $this->render('tournoi/index.html.twig', [
            'tournois' => $liste,
            'passes' => $passes,
            'peut_creer' => [] !== $this->modifiables(),
        ]);
    }

    #[Route('/nouveau', name: 'app_tournoi_nouveau', methods: ['GET', 'POST'])]
    public function nouveau(Request $request): Response
    {
        if (!$this->modifiables()) {
            throw $this->createAccessDeniedException();
        }
        $debut = \DateTimeImmutable::createFromFormat('!Y-m-d', $request->query->getString('date')) ?: new \DateTimeImmutable('today');
        $tournoi = (new Tournoi())->setDebut($debut);
        if (($id = $request->query->getString('categorie')) && ctype_digit($id)) {
            $tournoi->setCategorie(array_values(array_filter($this->modifiables(), fn (Categorie $c) => $c->getId() === (int) $id))[0] ?? null);
        }

        return $this->formulaire($request, $tournoi, 'Nouveau tournoi');
    }

    #[Route('/{id}', name: 'app_tournoi_voir', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function voir(Tournoi $tournoi): Response
    {
        return $this->render('tournoi/voir.html.twig', [
            't' => $tournoi,
            'peut_modifier' => $this->peutModifier($tournoi),
            'jours' => $this->jours($tournoi),
        ]);
    }

    #[Route('/{id}/modifier', name: 'app_tournoi_modifier', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function modifier(Request $request, Tournoi $tournoi): Response
    {
        $this->verifierModifiable($tournoi);

        return $this->formulaire($request, $tournoi, 'Modifier « ' . $tournoi . ' »');
    }

    /** Supprime le tournoi ; ses matchs restent dans « Matchs » (ils ne sont plus rattachés à un tournoi). */
    #[Route('/{id}/supprimer', name: 'app_tournoi_supprimer', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function supprimer(Request $request, Tournoi $tournoi): Response
    {
        $this->verifierModifiable($tournoi);
        if ($this->isCsrfTokenValid('supprimer-tournoi' . $tournoi->getId(), $request->getPayload()->getString('_token'))) {
            foreach ($tournoi->getMatchs() as $m) {
                $m->setTournoi(null);
            }
            $this->em->remove($tournoi);
            $this->em->flush();
            $this->addFlash('succes', 'Tournoi supprimé.');
        }

        return $this->redirectToRoute('app_tournoi_index');
    }

    /** Ajoute un match au tournoi : adversaire, jour, heure, score s'il est déjà joué. */
    #[Route('/{id}/match', name: 'app_tournoi_match', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function ajouterMatch(Request $request, Tournoi $tournoi, Apparence $apparence): Response
    {
        $this->verifierModifiable($tournoi);
        $p = $request->getPayload();
        if ($this->isCsrfTokenValid('match-tournoi' . $tournoi->getId(), $p->getString('_token'))) {
            $adversaire = mb_strtoupper(trim($p->getString('adversaire')));
            $jour = \DateTimeImmutable::createFromFormat('!Y-m-d', $p->getString('jour')) ?: $tournoi->getDebut();
            if (!\in_array($jour->format('Y-m-d'), array_map(fn ($j) => $j->format('Y-m-d'), $this->jours($tournoi)), true)) {
                $jour = $tournoi->getDebut();
            }
            [$pour, $contre] = [trim($p->getString('pour')), trim($p->getString('contre'))];
            $score = ctype_digit($pour) && ctype_digit($contre);
            if ('' === $adversaire) {
                $this->addFlash('erreur', 'Indiquez l\'adversaire.');
            } elseif (('' !== $pour || '' !== $contre) && !$score) {
                $this->addFlash('erreur', 'Score incomplet : les deux nombres, ou aucun si le match n\'est pas joué.');
            } else {
                $club = mb_strtoupper((string) $apparence->club()->getNom());
                $match = (new Rencontre())
                    ->setTournoi($tournoi)
                    ->setCategorie($tournoi->getCategorie())
                    ->setCompetition('Tournoi')
                    ->setDate($jour)
                    ->setHeure(trim($p->getString('heure')) ?: null)
                    ->setLieu($tournoi->getLieu())
                    ->setDomicile($club)
                    ->setExterieur($adversaire);
                if ($score) {
                    $match->setButsDomicile((int) $pour)->setButsExterieur((int) $contre);
                }
                $this->em->persist($match);
                $this->em->flush();
                $this->addFlash('succes', 'Match contre ' . $adversaire . ' ajouté.');
            }
        }

        return $this->redirectToRoute('app_tournoi_voir', ['id' => $tournoi->getId(), '_fragment' => 'matchs']);
    }

    /** Convocation du tournoi : celle de son premier match (une convocation par rencontre). */
    #[Route('/{id}/convocation', name: 'app_tournoi_convocation', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function convocation(Tournoi $tournoi): Response
    {
        $this->verifierModifiable($tournoi);
        $premier = $tournoi->getMatchs()->first();
        if (!$premier) {
            $this->addFlash('info', 'Ajoutez d\'abord un match du tournoi (même sans connaître l\'adversaire : « À définir ») : la convocation s\'y rattache.');

            return $this->redirectToRoute('app_tournoi_voir', ['id' => $tournoi->getId(), '_fragment' => 'matchs']);
        }

        if (!$this->isGranted(ActiviteVoter::MODIFIER_MATCH, $premier)) {
            $this->addFlash('erreur', 'Seuls l\'éducateur responsable de l\'équipe et les personnes qu\'il a désignées peuvent faire la convocation.');

            return $this->redirectToRoute('app_tournoi_voir', ['id' => $tournoi->getId()]);
        }

        return $this->redirectToRoute('app_convocation', ['id' => $premier->getId()]);
    }

    private function formulaire(Request $request, Tournoi $tournoi, string $titre): Response
    {
        $proposees = $this->modifiables();
        if ($tournoi->getCategorie() && !\in_array($tournoi->getCategorie(), $proposees, true)) {
            $proposees[] = $tournoi->getCategorie();
        }
        $form = $this->createForm(TournoiType::class, $tournoi, ['categories' => $proposees]);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            // Matchs déjà saisis : même équipe que le tournoi.
            foreach ($tournoi->getMatchs() as $m) {
                $m->setCategorie($tournoi->getCategorie());
            }
            $this->em->persist($tournoi);
            $this->em->flush();
            $this->addFlash('succes', 'Tournoi enregistré.');

            return $this->redirectToRoute('app_tournoi_voir', ['id' => $tournoi->getId()]);
        }

        return $this->render('commun/formulaire.html.twig', [
            'form' => $form,
            'titre' => $titre,
            'retour' => $tournoi->getId() ? $this->generateUrl('app_tournoi_voir', ['id' => $tournoi->getId()]) : $this->generateUrl('app_tournoi_index'),
        ] + ($tournoi->getId() ? ['suppression' => [
            'action' => $this->generateUrl('app_tournoi_supprimer', ['id' => $tournoi->getId()]),
            'jeton' => 'supprimer-tournoi' . $tournoi->getId(),
            'question' => 'Supprimer le tournoi « ' . $tournoi . ' » ? Ses matchs restent dans « Matchs ».',
        ]] : []));
    }

    /** @return list<\DateTimeImmutable> jours du tournoi */
    private function jours(Tournoi $tournoi): array
    {
        $jours = [];
        for ($j = $tournoi->getDebut(); $j <= $tournoi->getDernierJour() && \count($jours) < 15; $j = $j->modify('+1 day')) {
            $jours[] = $j;
        }

        return $jours;
    }

    /** @return list<Categorie> équipes que l'utilisateur gère */
    private function modifiables(): array
    {
        return $this->categories->findBy(['secteur' => $this->voter->secteursModifiables() ?: ['-']], ['ordre' => 'ASC']);
    }

    private function peutModifier(Tournoi $tournoi): bool
    {
        return $this->isGranted('ROLE_ADMIN') || \in_array($tournoi->getCategorie(), $this->modifiables(), true);
    }

    private function verifierModifiable(Tournoi $tournoi): void
    {
        if (!$this->peutModifier($tournoi)) {
            throw $this->createAccessDeniedException();
        }
    }
}
