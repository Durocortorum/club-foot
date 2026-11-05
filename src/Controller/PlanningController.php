<?php

namespace App\Controller;

use App\Entity\AbsenceEntrainement;
use App\Entity\Categorie;
use App\Entity\CreneauEntrainement;
use App\Entity\Entrainement;
use App\Entity\Rencontre;
use App\Entity\Utilisateur;
use App\Repository\CategorieRepository;
use App\Repository\CreneauEntrainementRepository;
use App\Repository\JoueurRepository;
use App\Security\ActiviteVoter;
use App\Security\JoueurVoter;
use App\Service\EnvoiConvocations;
use App\Service\PlanningEntrainements;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Planning des entraînements, côté encadrement : créneaux récurrents de l'équipe,
 * entraînements des semaines à venir, absences signalées par les familles, annulations.
 */
#[Route('/planning')]
class PlanningController extends AbstractController
{
    public function __construct(
        private readonly PlanningEntrainements $planning,
        private readonly JoueurVoter $voter,
        private readonly EntityManagerInterface $em,
    ) {
    }

    #[Route('', name: 'app_planning', methods: ['GET'])]
    public function index(Request $request, CategorieRepository $categories, CreneauEntrainementRepository $creneaux, JoueurRepository $joueurs, EnvoiConvocations $envoi): Response
    {
        $proposees = $categories->findBy(['secteur' => $this->voter->secteursModifiables()], ['ordre' => 'ASC', 'nom' => 'ASC']);
        if (!$proposees) {
            throw $this->createAccessDeniedException();
        }
        $categorie = $proposees[0];
        foreach ($proposees as $c) {
            if ($c->getId() === $request->query->getInt('categorie')) {
                $categorie = $c;
            }
        }
        // Période affichée : 3 semaines par défaut, 2 mois, ou jusqu'à la fin de la saison (30 juin).
        $periodes = ['3s' => '3 semaines', '2m' => '2 mois', 'saison' => 'Jusqu\'à la fin de la saison'];
        $periode = isset($periodes[$p = $request->query->getString('periode')]) ? $p : '3s';
        $finSaison = Rencontre::bornesSaison(Rencontre::saisonDe(new \DateTimeImmutable('today')))[1];
        $jours = match ($periode) {
            '2m' => 61,
            'saison' => max(PlanningEntrainements::HORIZON, (int) (new \DateTimeImmutable('today'))->diff($finSaison)->days),
            default => PlanningEntrainements::HORIZON,
        };
        $entrainements = $this->planning->aVenir([$categorie], $jours);

        return $this->render('planning/index.html.twig', [
            'categories' => $proposees,
            'categorie' => $categorie,
            'creneaux' => $creneaux->findBy(['categorie' => $categorie], ['jour' => 'ASC', 'heureDebut' => 'ASC']),
            'entrainements' => $entrainements,
            'annulations' => array_combine(
                array_map(fn (Entrainement $e) => $e->getId(), $entrainements),
                array_map(fn (Entrainement $e) => $e->isAnnule() ? $envoi->messageAnnulation($e) : null, $entrainements),
            ),
            'joueurs' => $joueurs->findBy(['categorie' => $categorie, 'ancien' => false], ['nom' => 'ASC', 'prenom' => 'ASC']),
            'jours' => CreneauEntrainement::JOURS,
            'motifs' => array_keys(AbsenceEntrainement::MOTIFS),
            'horizon' => PlanningEntrainements::HORIZON,
            'periodes' => $periodes,
            'periode' => $periode,
        ]);
    }

    /** Ajout (sans id) ou modification d'un créneau. */
    #[Route('/creneau/{id?}', name: 'app_planning_creneau', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function creneau(Request $request, ?CreneauEntrainement $creneau, CategorieRepository $categories, ValidatorInterface $validator): Response
    {
        $p = $request->getPayload();
        $creneau ??= (new CreneauEntrainement())->setCategorie($categories->find($p->getInt('categorie')));
        if (!$this->isGranted(ActiviteVoter::GERER, $creneau) || !$this->isCsrfTokenValid('creneau', $p->getString('_token'))) {
            throw $this->createAccessDeniedException();
        }
        $creneau->setJour($p->getInt('jour'))->setHeureDebut($p->getString('heureDebut'))
            ->setHeureFin($p->getString('heureFin'))->setLieu(trim($p->getString('lieu')) ?: null);

        $erreurs = $validator->validate($creneau);
        if (\count($erreurs)) {
            $this->addFlash('erreur', 'Créneau non enregistré : ' . $erreurs[0]->getMessage());
        } else {
            $this->planning->creneauModifie($creneau);
            $this->addFlash('succes', \sprintf('Créneau « %s » enregistré : les entraînements des %d prochains jours en tiennent compte.', $creneau, PlanningEntrainements::HORIZON));
        }

        return $this->retour($creneau->getCategorie());
    }

    #[Route('/creneau/{id}/supprimer', name: 'app_planning_creneau_supprimer', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[IsGranted(ActiviteVoter::GERER, 'creneau')]
    public function supprimerCreneau(Request $request, CreneauEntrainement $creneau): Response
    {
        $categorie = $creneau->getCategorie();
        if ($this->isCsrfTokenValid('supprimer-creneau' . $creneau->getId(), $request->getPayload()->getString('_token'))) {
            $this->planning->supprimerCreneau($creneau);
            $this->addFlash('succes', 'Créneau supprimé, avec ses entraînements à venir.');
        }

        return $this->retour($categorie);
    }

    /** Annuler (motif) ou rétablir un entraînement ; les familles sont prévenues d'une annulation. */
    #[Route('/entrainement/{id}/annulation', name: 'app_planning_annulation', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[IsGranted(ActiviteVoter::GERER, 'entrainement')]
    public function annulation(Request $request, Entrainement $entrainement, EnvoiConvocations $envoi): Response
    {
        $p = $request->getPayload();
        if (!$this->isCsrfTokenValid('annulation' . $entrainement->getId(), $p->getString('_token'))) {
            throw $this->createAccessDeniedException();
        }
        if ($p->getBoolean('retablir')) {
            $entrainement->retablir();
            $this->em->flush();
            $this->addFlash('succes', 'Entraînement rétabli. Pensez à prévenir les familles.');
        } else {
            $entrainement->annuler($p->getString('motif'));
            $this->em->flush();
            $emails = $envoi->annoncerAnnulation($entrainement);
            $this->addFlash('succes', \sprintf('Entraînement annulé, %d famille%s prévenue%2$s par e-mail. Pour les autres : message du groupe ci-dessous.', $emails, $emails > 1 ? 's' : ''));
        }

        return $this->retour($entrainement->getCategorie());
    }

    /** Absence notée par l'éducateur (prévenu par téléphone...), ou retirée. */
    #[Route('/entrainement/{id}/absence', name: 'app_planning_absence', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[IsGranted(ActiviteVoter::GERER, 'entrainement')]
    public function absence(Request $request, Entrainement $entrainement, JoueurRepository $joueurs): Response
    {
        $p = $request->getPayload();
        $joueur = $joueurs->find($p->getInt('joueur'));
        if (!$joueur || !$this->isCsrfTokenValid('absence' . $entrainement->getId(), $p->getString('_token'))) {
            throw $this->createAccessDeniedException();
        }
        /** @var Utilisateur $moi */
        $moi = $this->getUser();
        if ($p->getBoolean('retirer')) {
            if ($a = $entrainement->absenceDe($joueur)) {
                $this->planning->retirerAbsence($a);
            }
        } else {
            $this->planning->signalerAbsence($entrainement, $joueur, $p->getString('motif'), $p->getString('commentaire'), (string) $moi);
        }

        return $this->retour($entrainement->getCategorie(), 'entrainement-' . $entrainement->getId());
    }

    /** Retour au planning, à la même catégorie et à la même période (page d'où vient le formulaire). */
    private function retour(?Categorie $categorie, ?string $ancre = null): Response
    {
        $periode = $this->container->get('request_stack')->getCurrentRequest()?->getPayload()->getString('periode');

        return $this->redirect($this->generateUrl('app_planning', array_filter(['categorie' => $categorie?->getId(), 'periode' => $periode ?: null])) . ($ancre ? '#' . $ancre : ''));
    }
}
