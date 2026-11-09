<?php

namespace App\Controller;

use App\Entity\Convocation;
use App\Entity\Convoque;
use App\Entity\Joueur;
use App\Entity\Rencontre;
use App\Entity\Utilisateur;
use App\Entity\Message;
use App\Repository\ConvocationRepository;
use App\Service\Messagerie;
use App\Repository\ConvoqueRepository;
use Symfony\Component\Form\FormError;
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
    public function convocation(Request $request, Rencontre $rencontre, JoueurRepository $joueurs, ConvoqueRepository $convoques): Response
    {
        $convocation = $this->convocations->pour($rencontre) ?? new Convocation($rencontre);
        // Niveaux du dessous proposés : 1, 2, 3 ou tous (0), choisis au-dessus de la liste des joueurs.
        $this->niveauxDessous = \in_array($n = $request->query->getInt('dessous', 1), [0, 1, 2, 3], true) ? $n : 1;
        // Joueurs déjà convoqués ailleurs le même jour (ou le même week-end) : grisés, sauf s'ils sont déjà dans celle-ci.
        $occupes = $convoques->dejaConvoques($rencontre);
        $dejaIci = array_map(fn (Joueur $j) => $j->getId(), $convocation->getJoueurs());
        $ailleurs = fn (Joueur $j) => isset($occupes[$j->getId()])
            ? \sprintf('%s %s', $occupes[$j->getId()]->getEquipeAffichee(), $occupes[$j->getId()]->getDate()->format('d/m'))
            : null;
        $form = $this->createFormBuilder([
            'joueurs' => $convocation->getJoueurs(),
            'rdvHeure' => $convocation->getRdvHeure(),
            'rdvLieu' => $convocation->getRdvLieu() ?? $rencontre->getLieu(),
            'message' => $convocation->getMessage(),
        ])
            ->add('joueurs', EntityType::class, [
                'class' => Joueur::class,
                'choices' => $proposes = $this->joueursProposes($rencontre, $convocation, $joueurs),
                'choice_label' => fn (Joueur $j) => $j->getNom() . ' ' . $j->getPrenom(),
                'choice_attr' => fn (Joueur $j) => $ailleurs($j)
                    ? ['data-ailleurs' => $ailleurs($j)] + (\in_array($j->getId(), $dejaIci, true) ? [] : ['disabled' => 'disabled'])
                    : [],
                'group_by' => fn (Joueur $j) => $this->groupeJoueur($j, $rencontre),
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
        if ($form->isSubmitted()) {
            // Garde-fou (case grisée contournée) : pas de nouveau joueur déjà convoqué ailleurs ce jour ou ce week-end.
            foreach ($form->get('joueurs')->getData() ?? [] as $j) {
                if ($ailleurs($j) && !\in_array($j->getId(), $dejaIci, true)) {
                    $form->get('joueurs')->addError(new FormError(\sprintf('%s est déjà convoqué(e) avec %s : un joueur ne peut pas jouer deux matchs le même jour ou le même week-end.', $j->getNomComplet(), $ailleurs($j))));
                }
            }
        }

        if ($form->isSubmitted() && $form->isValid()) {
            $d = $form->getData();
            $convocation->setJoueurs($d['joueurs'])->setRdvHeure($d['rdvHeure'])->setRdvLieu($d['rdvLieu'])->setMessage($d['message']);
            $this->em->persist($convocation);
            $this->em->flush();
            $this->addFlash('succes', $convocation->getEnvoyeeLe()
                ? 'Convocation modifiée. Pensez à prévenir les joueurs ajoutés (bouton « Envoyer »).'
                : 'Convocation enregistrée. Vérifiez-la, puis envoyez-la aux familles.');

            return $this->redirectToRoute('app_convocation', array_filter(['id' => $rencontre->getId(), 'dessous' => 1 === $this->niveauxDessous ? null : $this->niveauxDessous], fn ($v) => null !== $v));
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
            'niveaux_dessous' => $this->niveauxDessous,
            'niveaux_vides' => $this->niveauxVides($rencontre, $proposes),
        ]);
    }

    /** Envoi aux familles, ou relance de celles qui n'ont pas répondu. */
    #[Route('/envoyer', name: 'app_convocation_envoyer', methods: ['POST'])]
    public function envoyer(Request $request, Rencontre $rencontre, Messagerie $messagerie): Response
    {
        $convocation = $this->convocations->pour($rencontre) ?? throw $this->createNotFoundException();
        if (!$this->isCsrfTokenValid('envoyer-convocation' . $convocation->getId(), $request->getPayload()->getString('_token'))) {
            throw $this->createAccessDeniedException();
        }
        $relance = $request->getPayload()->getBoolean('relance');
        // Joueurs prévenus : tous les convoqués, ou ceux qui n'ont pas répondu pour une relance.
        $prevenus = array_values(array_map(fn (Convoque $c) => $c->getJoueur(), array_filter($convocation->getConvoques(), fn (Convoque $c) => !$relance || null === $c->getReponse())));
        $r = $this->envoi->envoyer($convocation, $relance);

        // La convocation arrive aussi dans la messagerie interne des familles (sans second e-mail).
        if ($prevenus) {
            /** @var Utilisateur $moi */
            $moi = $this->getUser();
            $texte = $this->envoi->pourMessagerie($convocation, $relance);
            $messagerie->envoyer((new Message())->setAuteur($moi)->setSujet($texte['sujet'])->setCorps($texte['corps'])
                ->setCible('convocation')->setCibleLibelle($texte['cible'])
                ->setCategorie($rencontre->getCategorie())->setRencontre($rencontre), $prevenus, false);
        }

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
        [$dessous, $dessus] = $this->niveauxVoisins($categorie);
        $qb = $joueurs->createQueryBuilder('j')
            ->join('j.categorie', 'c')
            ->andWhere('j.ancien = false')
            ->orderBy('c.ordre')->addOrderBy('j.nom')->addOrderBy('j.prenom');
        // Le secteur du match, plus les niveaux du dessous choisis et celui du dessus (même d'un autre secteur).
        $voisines = array_filter([...$dessous, $dessus]);
        if ($voisines) {
            $qb->andWhere('c.secteur = :s OR c IN (:voisines)')->setParameter('voisines', $voisines);
        } else {
            $qb->andWhere('c.secteur = :s');
        }
        $liste = $qb->setParameter('s', $categorie->getSecteur())->getQuery()->getResult();

        $parId = [];
        foreach ([...$liste, ...$convocation->getJoueurs()] as $j) {
            $parId[$j->getId()] = $j;
        }
        // La catégorie du match, puis le niveau du dessus, les niveaux du dessous (du plus proche au plus jeune),
        // et les autres catégories.
        $rang = fn (Joueur $j) => match (true) {
            $j->getCategorie() === $categorie => [0, 0],
            null !== $dessus && $j->getCategorie() === $dessus => [1, 0],
            \in_array($j->getCategorie(), $dessous, true) => [2, array_search($j->getCategorie(), $dessous, true)],
            default => [3, $j->getCategorie()?->getOrdre()],
        };
        uasort($parId, fn (Joueur $a, Joueur $b) => [$rang($a), $a->getNom(), $a->getPrenom()] <=> [$rang($b), $b->getNom(), $b->getPrenom()]);

        return array_values($parId);
    }

    /** Niveaux du dessous proposés (1, 2, 3 ; 0 : tous), choisis sur la page. */
    private int $niveauxDessous = 1;

    /** @var list<\App\Entity\Categorie>|null */
    private ?array $categoriesOrdonnees = null;

    /**
     * Catégories au-dessous (du plus proche au plus jeune, autant que de niveaux choisis) et juste au-dessus
     * de celle du match, dans l'ordre des catégories du club (du plus jeune au plus haut niveau : ... DH, CFA 2, PRO).
     *
     * @return array{0: list<\App\Entity\Categorie>, 1: ?\App\Entity\Categorie}
     */
    private function niveauxVoisins(?\App\Entity\Categorie $categorie): array
    {
        if (!$categorie) {
            return [[], null];
        }
        // Liste lue une fois par page (appelée pour chaque joueur de la liste).
        $toutes = $this->categoriesOrdonnees ??= $this->em->getRepository(\App\Entity\Categorie::class)->findBy([], ['ordre' => 'ASC', 'nom' => 'ASC']);
        $i = array_search($categorie, $toutes, true);
        if (false === $i) {
            return [[], null];
        }
        $dessous = array_reverse(\array_slice($toutes, 0, $i));

        return [$this->niveauxDessous > 0 ? \array_slice($dessous, 0, $this->niveauxDessous) : $dessous, $toutes[$i + 1] ?? null];
    }

    /**
     * Niveaux voisins sans aucun joueur à proposer (pas de groupe affiché) : signalés pour ne pas surprendre.
     *
     * @param list<Joueur> $proposes
     *
     * @return list<string> ex. « DH · 1 niveau en dessous »
     */
    private function niveauxVides(Rencontre $rencontre, array $proposes): array
    {
        [$dessous, $dessus] = $this->niveauxVoisins($rencontre->getCategorie());
        $avecJoueurs = array_map(fn (Joueur $j) => $j->getCategorie(), $proposes);
        $vides = [];
        foreach (array_filter([$dessus, ...$dessous]) as $c) {
            if (!\in_array($c, $avecJoueurs, true)) {
                $n = array_search($c, $dessous, true);
                $vides[] = $c === $dessus ? $c . ' · niveau du dessus' : \sprintf('%s · %d %s en dessous', $c, $n + 1, $n ? 'niveaux' : 'niveau');
            }
        }

        return $vides;
    }

    /** Groupe d'un joueur dans la liste : catégorie du match, niveau du dessus / du dessous, autres catégories du secteur. */
    private function groupeJoueur(Joueur $j, Rencontre $rencontre): string
    {
        $c = $j->getCategorie();
        [$dessous, $dessus] = $this->niveauxVoisins($rencontre->getCategorie());
        $n = array_search($c, $dessous, true);

        return match (true) {
            $c === $rencontre->getCategorie() => (string) $c,
            null !== $c && $c === $dessus => $c . ' · niveau du dessus',
            false !== $n => \sprintf('%s · %d %s en dessous', $c, $n + 1, $n ? 'niveaux' : 'niveau'),
            default => 'Autres catégories',
        };
    }
}
