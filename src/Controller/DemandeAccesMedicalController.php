<?php

namespace App\Controller;

use App\Entity\DemandeAccesMedical;
use App\Entity\Utilisateur;
use App\Repository\DemandeAccesMedicalRepository;
use App\Service\DemandesMedicales;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Validator\Constraints\Length;

/**
 * Demande d'accès aux données médicales par un membre de l'encadrement, et réponse
 * d'un médecin du club ou de l'administrateur (depuis le tableau des droits).
 */
class DemandeAccesMedicalController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly DemandesMedicales $demandes,
    ) {
    }

    #[Route('/acces-medical/demande', name: 'app_demande_medicale', methods: ['GET', 'POST'])]
    public function demander(Request $request, DemandeAccesMedicalRepository $depot): Response
    {
        /** @var Utilisateur $moi */
        $moi = $this->getUser();
        $sections = [];
        foreach (Utilisateur::SECTIONS_MEDICALES as $s) {
            $sections[Utilisateur::SECTIONS[$s]['libelle'] . ' — ' . Utilisateur::DESCRIPTIONS_ROLES[$s]] = $s;
        }
        $form = $this->createFormBuilder(['section' => $request->query->getString('section') ?: 'infirmerie', 'niveau' => 1])
            ->add('section', ChoiceType::class, ['label' => 'Accès demandé', 'choices' => $sections, 'expanded' => true])
            ->add('niveau', ChoiceType::class, [
                'label' => 'Niveau',
                'choices' => ['Voir (consultation seule)' => 1, 'Modifier (consultation et saisie)' => 2],
                'expanded' => true,
            ])
            ->add('motif', TextareaType::class, [
                'label' => 'Motif (facultatif)',
                'required' => false,
                'help' => 'Par exemple : « suivi de la blessure de Léo », « kiné de l\'équipe U17 ».',
                'constraints' => [new Length(max: 255)],
            ])
            ->getForm();
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            ['section' => $section, 'niveau' => $niveau, 'motif' => $motif] = $form->getData();
            if ($moi->getNiveau($section) >= $niveau || ($moi->isMedecin() || $moi->isAdmin())) {
                $form->addError(new FormError('Vous avez déjà cet accès.'));
            } elseif ($depot->enAttentePour($moi, $section)) {
                $form->addError(new FormError('Vous avez déjà une demande en attente pour cet accès.'));
            } else {
                $demande = new DemandeAccesMedical($moi, $section, $niveau, $motif ? trim($motif) : null);
                $this->em->persist($demande);
                $this->em->flush();
                $prevenus = $this->demandes->prevenirNouvelle($demande);
                $this->addFlash('succes', $prevenus
                    ? 'Demande envoyée au médecin du club. Vous serez prévenu(e) par e-mail de sa réponse.'
                    : 'Demande enregistrée. Prévenez l\'administrateur du club : aucun e-mail n\'a pu lui être envoyé.');

                return $this->redirectToRoute('app_demande_medicale');
            }
        }

        return $this->render('medical/demande.html.twig', [
            'form' => $form,
            'mes_demandes' => $depot->dernieresDe($moi),
        ]);
    }

    #[Route('/acces-medical/demande/{id}/{decision}', name: 'app_demande_medicale_traiter', requirements: ['id' => '\d+', 'decision' => 'accorder|refuser'], methods: ['POST'])]
    public function traiter(Request $request, DemandeAccesMedical $demande, string $decision): Response
    {
        if (!$this->demandes->peutTraiter()) {
            throw $this->createAccessDeniedException();
        }
        if (!$demande->isEnAttente() || !$this->isCsrfTokenValid('traiter-demande' . $demande->getId(), $request->getPayload()->getString('_token'))) {
            $this->addFlash('erreur', 'Cette demande a déjà été traitée, ou la page a expiré.');

            return $this->redirect($this->generateUrl('app_utilisateur_droits') . '#demandes');
        }

        $demandeur = $demande->getDemandeur();
        $echeance = null;
        if ('accorder' === $decision) {
            $echeance = Utilisateur::echeanceMedicale($request->getPayload()->getString('duree', '30'));
            if (null === $echeance) {
                throw $this->createAccessDeniedException('Durée inconnue.');
            }
            // Jamais moins que l'accès déjà en vigueur.
            $demandeur->setNiveau($demande->getSection(), max($demande->getNiveau(), $demandeur->getNiveau($demande->getSection())), $echeance);
        }
        $demande->traiter('accorder' === $decision ? DemandeAccesMedical::ACCORDEE : DemandeAccesMedical::REFUSEE, (string) $this->getUser());
        $this->em->flush();
        $this->demandes->prevenirDecision($demande, $echeance);
        $this->addFlash('succes', \sprintf('Demande de %s %s%s.', $demandeur, 'accorder' === $decision ? 'accordée' : 'refusée',
            $echeance ? ' jusqu\'au ' . $echeance->format('d/m/Y') : ''));

        return $this->redirect($this->generateUrl('app_utilisateur_droits') . '#demandes');
    }
}
