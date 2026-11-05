<?php

namespace App\Controller;

use App\Entity\Convocation;
use App\Entity\Convoque;
use App\Entity\Joueur;
use App\Entity\Rencontre;
use App\Entity\Utilisateur;
use App\Repository\ConvocationRepository;
use App\Repository\JoueurRepository;
use App\Security\ActiviteVoter;
use App\Service\EnvoiConvocations;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Validator\Constraints\Count;
use Symfony\Component\Validator\Constraints\Length;

/**
 * Convocation à un match, côté encadrement : joueurs appelés, rendez-vous, envoi aux familles,
 * suivi des réponses (et saisie d'une réponse reçue autrement : téléphone, au bord du terrain...).
 */
#[Route('/matchs/{id}/convocation', requirements: ['id' => '\d+'])]
#[IsGranted(ActiviteVoter::GERER, 'rencontre')]
class ConvocationController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ConvocationRepository $convocations,
        private readonly EnvoiConvocations $envoi,
    ) {
    }

    #[Route('', name: 'app_convocation', methods: ['GET', 'POST'])]
    public function convocation(Request $request, Rencontre $rencontre, JoueurRepository $joueurs): Response
    {
        $convocation = $this->convocations->pour($rencontre) ?? new Convocation($rencontre);
        $form = $this->createFormBuilder([
            'joueurs' => $convocation->getJoueurs(),
            'rdvHeure' => $convocation->getRdvHeure(),
            'rdvLieu' => $convocation->getRdvLieu() ?? $rencontre->getLieu(),
            'message' => $convocation->getMessage(),
        ])
            ->add('joueurs', EntityType::class, [
                'class' => Joueur::class,
                'choices' => $this->joueursProposes($rencontre, $convocation, $joueurs),
                'choice_label' => fn (Joueur $j) => $j->getNom() . ' ' . $j->getPrenom(),
                'group_by' => fn (Joueur $j) => $j->getCategorie() === $rencontre->getCategorie() ? (string) $j->getCategorie() : 'Autres catégories',
                'multiple' => true,
                'expanded' => true,
                'label' => 'Joueurs convoqués',
                'constraints' => [new Count(min: 1, minMessage: 'Cochez au moins un joueur. Pour annuler la convocation, utilisez le bouton « Supprimer » en haut de la page.')],
            ])
            ->add('rdvHeure', TextType::class, ['label' => 'Heure du rendez-vous', 'required' => false, 'attr' => ['placeholder' => 'ex. 13h15'], 'constraints' => [new Length(max: 10)]])
            ->add('rdvLieu', TextType::class, ['label' => 'Lieu du rendez-vous', 'required' => false, 'constraints' => [new Length(max: 120)]])
            ->add('message', TextareaType::class, ['label' => 'Message aux familles', 'required' => false, 'attr' => ['rows' => 3, 'placeholder' => 'ex. Prévoir la tenue blanche, goûter après le match.']])
            ->getForm();
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $d = $form->getData();
            $convocation->setJoueurs($d['joueurs'])->setRdvHeure($d['rdvHeure'])->setRdvLieu($d['rdvLieu'])->setMessage($d['message']);
            $this->em->persist($convocation);
            $this->em->flush();
            $this->addFlash('succes', $convocation->getEnvoyeeLe()
                ? 'Convocation modifiée. Pensez à prévenir les joueurs ajoutés (bouton « Envoyer »).'
                : 'Convocation enregistrée. Vérifiez-la, puis envoyez-la aux familles.');

            return $this->redirectToRoute('app_convocation', ['id' => $rencontre->getId()]);
        }

        return $this->render('convocation/index.html.twig', [
            'rencontre' => $rencontre,
            'convocation' => $convocation->getId() ? $convocation : null,
            'form' => $form,
            'message_groupe' => $convocation->getId() ? $this->envoi->messageGroupe($convocation) : null,
            'sms' => array_combine(
                array_map(fn (Convoque $c) => $c->getId(), $convocation->getConvoques()),
                array_map(fn (Convoque $c) => $this->envoi->sms($c), $convocation->getConvoques()),
            ),
            'motifs' => Convoque::MOTIFS,
        ]);
    }

    /** Envoi aux familles, ou relance de celles qui n'ont pas répondu. */
    #[Route('/envoyer', name: 'app_convocation_envoyer', methods: ['POST'])]
    public function envoyer(Request $request, Rencontre $rencontre): Response
    {
        $convocation = $this->convocations->pour($rencontre) ?? throw $this->createNotFoundException();
        if (!$this->isCsrfTokenValid('envoyer-convocation' . $convocation->getId(), $request->getPayload()->getString('_token'))) {
            throw $this->createAccessDeniedException();
        }
        $relance = $request->getPayload()->getBoolean('relance');
        $r = $this->envoi->envoyer($convocation, $relance);

        $this->addFlash('succes', \sprintf('%s : %d e-mail%s envoyé%2$s.', $relance ? 'Relance' : 'Convocation envoyée', $r['emails'], $r['emails'] > 1 ? 's' : ''));
        if ($r['sans_compte']) {
            $this->addFlash('info', \sprintf(
                'Sans compte famille actif, à prévenir par WhatsApp ou SMS : %s.',
                implode(', ', array_map(fn (Joueur $j) => $j->getNomComplet(), $r['sans_compte'])),
            ));
        }
        if ($r['echecs']) {
            $this->addFlash('erreur', 'Envoi impossible vers : ' . implode(', ', $r['echecs']) . '.');
        }

        return $this->redirectToRoute('app_convocation', ['id' => $rencontre->getId()]);
    }

    /** Réponse saisie par l'éducateur (reçue par téléphone, SMS...), ou effacée. */
    #[Route('/reponse/{convoque}', name: 'app_convocation_reponse', requirements: ['convoque' => '\d+'], methods: ['POST'])]
    public function reponse(Request $request, Rencontre $rencontre, Convoque $convoque): Response
    {
        if ($convoque->getConvocation()->getRencontre() !== $rencontre
            || !$this->isCsrfTokenValid('reponse-convoque' . $convoque->getId(), $request->getPayload()->getString('_token'))) {
            throw $this->createAccessDeniedException();
        }
        /** @var Utilisateur $moi */
        $moi = $this->getUser();
        $reponse = $request->getPayload()->getString('reponse') ?: null;
        $convoque->repondre(\in_array($reponse, [Convoque::PRESENT, Convoque::ABSENT], true) ? $reponse : null, $request->getPayload()->getString('motif'), (string) $moi);
        $this->em->flush();

        return $this->redirect($this->generateUrl('app_convocation', ['id' => $rencontre->getId()]) . '#reponses');
    }

    #[Route('/supprimer', name: 'app_convocation_supprimer', methods: ['POST'])]
    public function supprimer(Request $request, Rencontre $rencontre): Response
    {
        $convocation = $this->convocations->pour($rencontre) ?? throw $this->createNotFoundException();
        if ($this->isCsrfTokenValid('supprimer-convocation' . $convocation->getId(), $request->getPayload()->getString('_token'))) {
            $retires = $this->retirerDeLaFeuille($rencontre, $convocation);
            $this->em->remove($convocation);
            $this->em->flush();
            $this->addFlash('succes', 'Convocation supprimée.' . ($retires ? \sprintf(' %d joueur%s retiré%2$s de la feuille de match, où ils n\'avaient encore rien de saisi.', $retires, $retires > 1 ? 's' : '') : ''));
        }

        return $this->redirectToRoute('app_rencontre_voir', ['id' => $rencontre->getId()]);
    }

    /**
     * Convocation supprimée (erreur) : ses joueurs quittent aussi la feuille de match, mais seulement
     * les lignes restées telles que le préremplissage les a créées. Un numéro, un poste, une note,
     * un carton, une appréciation, un but ou un remplacement : la ligne est gardée.
     */
    private function retirerDeLaFeuille(Rencontre $rencontre, Convocation $convocation): int
    {
        $convoques = array_map(fn (Joueur $j) => $j->getId(), $convocation->getJoueurs());
        $utilises = [];
        foreach ($rencontre->getButs() as $b) {
            $utilises[] = $b->getButeur()?->getId();
            $utilises[] = $b->getPasseur()?->getId();
        }
        foreach ($rencontre->getRemplacements() as $r) {
            $utilises[] = $r->getSortant()?->getId();
            $utilises[] = $r->getEntrant()?->getId();
        }

        $retires = 0;
        foreach ($rencontre->getParticipations() as $p) {
            $id = $p->getJoueur()?->getId();
            $vierge = null === $p->getNumero() && '' === trim((string) $p->getPoste()) && null === $p->getNote()
                && !$p->getCarton() && '' === trim((string) $p->getAppreciation());
            if (\in_array($id, $convoques, true) && $vierge && !\in_array($id, $utilises, true)) {
                $rencontre->removeParticipation($p);
                ++$retires;
            }
        }

        return $retires;
    }

    /**
     * Joueurs actifs de la catégorie du match, puis ceux des autres catégories du même secteur
     * (surclassement), plus les joueurs déjà convoqués.
     *
     * @return list<Joueur>
     */
    private function joueursProposes(Rencontre $rencontre, Convocation $convocation, JoueurRepository $joueurs): array
    {
        $categorie = $rencontre->getCategorie();
        $liste = $joueurs->createQueryBuilder('j')
            ->join('j.categorie', 'c')
            ->andWhere('j.ancien = false')
            ->andWhere('c.secteur = :s')->setParameter('s', $categorie->getSecteur())
            ->orderBy('c.ordre')->addOrderBy('j.nom')->addOrderBy('j.prenom')
            ->getQuery()->getResult();

        $parId = [];
        foreach ([...$liste, ...$convocation->getJoueurs()] as $j) {
            $parId[$j->getId()] = $j;
        }
        // La catégorie du match en premier.
        uasort($parId, fn (Joueur $a, Joueur $b) => [$a->getCategorie() !== $categorie, $a->getCategorie()?->getOrdre(), $a->getNom(), $a->getPrenom()]
            <=> [$b->getCategorie() !== $categorie, $b->getCategorie()?->getOrdre(), $b->getNom(), $b->getPrenom()]);

        return array_values($parId);
    }
}
