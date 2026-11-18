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
    public function convocation(Request $request, Rencontre $rencontre, JoueurRepository $joueurs, ConvoqueRepository $convoques, Messagerie $messagerie): Response
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
            $gardes = array_map(fn (Joueur $j) => $j->getId(), [...$d['joueurs']]);
            $retires = array_values(array_filter($convocation->getJoueurs(), fn (Joueur $j) => !\in_array($j->getId(), $gardes, true)));
            $convocation->setJoueurs($d['joueurs'])->setRdvHeure($d['rdvHeure'])->setRdvLieu($d['rdvLieu'])->setMessage($d['message']);
            $this->em->persist($convocation);
            if ($retires) {
                $this->retirerDeLaFeuille($rencontre, $convocation, $retires);
            }
            $this->em->flush();
            $this->prevenirRetrait($convocation, $retires, $messagerie);
            if ('envoyer' === $request->getPayload()->getString('suite')) {
                // « Enregistrer et envoyer aux parents » : à tous la première fois, ensuite aux seuls joueurs ajoutés.
                $this->diffuser($convocation, $messagerie, false, null !== $convocation->getEnvoyeeLe());
            } else {
                $nouveaux = \count(array_filter($convocation->getConvoques(), fn (Convoque $c) => null === $c->getEnvoyeLe()));
                $this->addFlash('succes', $convocation->getEnvoyeeLe() && $nouveaux
                    ? \sprintf('Convocation enregistrée. %d joueur%s ajouté%2$s pas encore prévenu%2$s : bouton « Envoyer aux parents ».', $nouveaux, $nouveaux > 1 ? 's' : '')
                    : 'Convocation enregistrée' . ($convocation->getEnvoyeeLe() ? '.' : ', pas encore envoyée aux parents.'));
            }

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
            // Remplaçants possibles (cadre « Remplacer un joueur ») : proposés, ni convoqués ici, ni ailleurs ce week-end.
            'remplacants' => array_reduce($proposes, function (array $groupes, Joueur $j) use ($dejaIci, $ailleurs, $rencontre) {
                if (!\in_array($j->getId(), $dejaIci, true) && !$ailleurs($j)) {
                    $groupes[$this->groupeJoueur($j, $rencontre)][] = $j;
                }

                return $groupes;
            }, []),
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
        $this->diffuser($convocation, $messagerie, $request->getPayload()->getBoolean('relance'), $request->getPayload()->getBoolean('nouveaux'), $request->getPayload()->getBoolean('definitive'));

        return $this->redirectToRoute('app_convocation', ['id' => $rencontre->getId()]);
    }

    /**
     * Envoi aux parents (e-mail, notification, messagerie interne) : à tous, aux seuls joueurs sans réponse (relance),
     * ou aux seuls joueurs dont la famille n'a pas encore été prévenue (ajoutés après l'envoi, par exemple pour
     * remplacer des absents).
     */
    private function diffuser(Convocation $convocation, Messagerie $messagerie, bool $relance = false, bool $nouveaux = false, bool $definitive = false): void
    {
        $rencontre = $convocation->getRencontre();
        $prevenus = array_values(array_map(fn (Convoque $c) => $c->getJoueur(), array_filter($convocation->getConvoques(),
            fn (Convoque $c) => (!$relance || null === $c->getReponse()) && (!$nouveaux || null === $c->getEnvoyeLe())
                && (!$definitive || Convoque::ABSENT !== $c->getReponse()))));
        if (!$prevenus) {
            $this->addFlash('info', $definitive ? 'Tous les convoqués sont absents : pas de convocation définitive à envoyer.' : 'Toutes les familles avaient déjà reçu la convocation : rien de nouveau à envoyer.');

            return;
        }
        $r = $this->envoi->envoyer($convocation, $relance, $nouveaux, $definitive);

        // La convocation arrive aussi dans la messagerie interne des familles (sans second e-mail).
        /** @var Utilisateur $moi */
        $moi = $this->getUser();
        $texte = $this->envoi->pourMessagerie($convocation, $relance, $definitive);
        $messagerie->envoyer((new Message())->setAuteur($moi)->setSujet($texte['sujet'])->setCorps($texte['corps'])
            ->setCible('convocation')->setCibleLibelle($texte['cible'])
            ->setCategorie($rencontre->getCategorie())->setRencontre($rencontre), $prevenus, false);

        $this->addFlash('succes', match (true) {
            $definitive => \sprintf('Convocation définitive envoyée aux parents des %d joueur%s retenu%2$s : %d e-mail%s.', \count($prevenus), \count($prevenus) > 1 ? 's' : '', $r['emails'], $r['emails'] > 1 ? 's' : ''),
            $relance => \sprintf('Relance : %d e-mail%s envoyé%2$s.', $r['emails'], $r['emails'] > 1 ? 's' : ''),
            $nouveaux => \sprintf('Convocation envoyée aux parents des %d joueur%s ajouté%2$s (%s) : %d e-mail%s.', \count($prevenus), \count($prevenus) > 1 ? 's' : '',
                implode(', ', array_map(fn (Joueur $j) => $j->getNomComplet(), $prevenus)), $r['emails'], $r['emails'] > 1 ? 's' : ''),
            default => \sprintf('Convocation envoyée aux parents : %d e-mail%s envoyé%2$s.', $r['emails'], $r['emails'] > 1 ? 's' : ''),
        });
        if ($r['sans_compte']) {
            $this->addFlash('info', \sprintf(
                'Sans compte famille actif, à prévenir par WhatsApp ou SMS : %s.',
                implode(', ', array_map(fn (Joueur $j) => $j->getNomComplet(), $r['sans_compte'])),
            ));
        }
        if ($r['echecs']) {
            $this->addFlash('erreur', 'Envoi impossible vers : ' . implode(', ', $r['echecs']) . '.');
        }
    }

    /**
     * Remplacer un joueur (malade, blessé, empêché) entre la convocation et le match : le remplaçant est convoqué,
     * ses parents prévenus si la convocation était déjà envoyée ; le joueur remplacé est noté absent.
     */
    #[Route('/remplacer', name: 'app_convocation_remplacer', methods: ['POST'])]
    public function remplacer(Request $request, Rencontre $rencontre, JoueurRepository $joueurs, ConvoqueRepository $convoques, Messagerie $messagerie): Response
    {
        $convocation = $this->convocations->pour($rencontre) ?? throw $this->createNotFoundException();
        if (!$this->isCsrfTokenValid('remplacer' . $convocation->getId(), $request->getPayload()->getString('_token'))) {
            throw $this->createAccessDeniedException();
        }
        $retour = $this->redirectToRoute('app_convocation', ['id' => $rencontre->getId(), '_fragment' => 'reponses']);
        // Remplaçant : un joueur proposable (tous les niveaux), pas déjà convoqué ici ni ailleurs ce jour ou ce week-end.
        $this->niveauxDessous = 0;
        $remplacant = null;
        foreach ($this->joueursProposes($rencontre, $convocation, $joueurs) as $j) {
            if ($j->getId() === $request->getPayload()->getInt('joueur')) {
                $remplacant = $j;
            }
        }
        $occupes = $convoques->dejaConvoques($rencontre);
        if (!$remplacant || \in_array($remplacant, $convocation->getJoueurs(), true) || isset($occupes[$remplacant->getId()])) {
            $this->addFlash('erreur', 'Choisissez un joueur qui n\'est pas déjà convoqué (ici ou pour un autre match ce week-end).');

            return $retour;
        }
        /** @var Utilisateur $moi */
        $moi = $this->getUser();
        // Le joueur remplacé : noté absent, avec le motif (malade, blessé…), s'il n'était pas déjà absent.
        foreach ($convocation->getConvoques() as $c) {
            if ($c->getId() === $request->getPayload()->getInt('remplace') && Convoque::ABSENT !== $c->getReponse()) {
                $c->repondre(Convoque::ABSENT, $request->getPayload()->getString('motif') ?: 'Remplacé', 'Éducateur : ' . $moi);
                $remplace = $c->getJoueur();
            }
        }
        $convocation->setJoueurs([...$convocation->getJoueurs(), $remplacant]);
        $this->em->flush();
        $this->addFlash('succes', \sprintf('%s convoqué(e)%s.', $remplacant->getNomComplet(), isset($remplace) ? ' à la place de ' . $remplace->getNomComplet() : ''));
        if ($convocation->getEnvoyeeLe()) {
            $this->diffuser($convocation, $messagerie, false, true);
        } else {
            $this->addFlash('info', 'La convocation n\'est pas encore envoyée : « Enregistrer et envoyer aux parents » quand elle est prête.');
        }

        return $retour;
    }

    /** Retirer un joueur de la convocation (erreur, décision du staff) : sa famille est prévenue si elle était déjà envoyée. */
    #[Route('/retirer/{convoque}', name: 'app_convocation_retirer', requirements: ['convoque' => '\d+'], methods: ['POST'])]
    public function retirer(Request $request, Rencontre $rencontre, Convoque $convoque, Messagerie $messagerie): Response
    {
        $convocation = $convoque->getConvocation();
        if ($convocation->getRencontre() !== $rencontre
            || !$this->isCsrfTokenValid('retirer-convoque' . $convoque->getId(), $request->getPayload()->getString('_token'))) {
            throw $this->createAccessDeniedException();
        }
        $joueur = $convoque->getJoueur();
        if (1 === \count($convocation->getConvoques())) {
            $this->addFlash('erreur', \sprintf('%s est le seul joueur convoqué : pour annuler la convocation, utilisez « Supprimer » en haut de la page.', $joueur->getNomComplet()));

            return $this->redirect($this->generateUrl('app_convocation', ['id' => $rencontre->getId()]) . '#reponses');
        }
        $convocation->setJoueurs(array_filter($convocation->getJoueurs(), fn (Joueur $j) => $j !== $joueur));
        $this->retirerDeLaFeuille($rencontre, $convocation, [$joueur]);
        $this->em->flush();
        $this->addFlash('succes', $joueur->getNomComplet() . ' n\'est plus convoqué(e).');
        $this->prevenirRetrait($convocation, [$joueur], $messagerie);

        return $this->redirect($this->generateUrl('app_convocation', ['id' => $rencontre->getId()]) . '#reponses');
    }

    /**
     * Joueurs retirés d'une convocation déjà envoyée : leur famille est prévenue par e-mail et dans la messagerie
     * interne ; celles sans compte actif sont signalées pour un appel ou un SMS.
     *
     * @param list<Joueur> $retires
     */
    private function prevenirRetrait(Convocation $convocation, array $retires, Messagerie $messagerie): void
    {
        if (!$retires || !$convocation->getEnvoyeeLe()) {
            return;
        }
        $r = $convocation->getRencontre();
        $quand = \sprintf('%s %s', mb_strtolower(EnvoiConvocations::JOURS[(int) $r->getDate()->format('w')]), $r->getDate()->format('d/m'));
        /** @var Utilisateur $moi */
        $moi = $this->getUser();
        $message = (new Message())->setAuteur($moi)
            ->setSujet(mb_substr(\sprintf('Changement de convocation – %s contre %s, %s', $r->getEquipeAffichee(), $r->getAdversaire(), $quand), 0, 120))
            ->setCorps(\sprintf("Changement : le joueur n'est finalement plus convoqué pour le match %s – %s du %s.\n\nPour toute question, répondez à ce message.", $r->getDomicile(), $r->getExterieur(), $quand))
            ->setCible('retrait')->setCibleLibelle(\sprintf('Retirés de la convocation – %s contre %s, %s', $r->getEquipeAffichee(), $r->getAdversaire(), $r->getDate()->format('d/m')))
            ->setCategorie($r->getCategorie())->setRencontre($r);
        $resultat = $messagerie->envoyer($message, $retires, true);
        $this->addFlash('info', \sprintf('Famille%s prévenue%1$s du retrait (e-mail et messagerie).', \count($retires) > 1 ? 's' : ''));
        if ($resultat['sans_compte']) {
            $this->addFlash('info', 'Sans compte famille actif, à prévenir par téléphone ou SMS : ' . implode(', ', array_map(fn (Joueur $j) => $j->getNomComplet(), $resultat['sans_compte'])) . '.');
        }
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
        $convoque->repondre(\in_array($reponse, [Convoque::PRESENT, Convoque::ABSENT], true) ? $reponse : null, $request->getPayload()->getString('motif'), 'Éducateur : ' . $moi);
        $this->em->flush();

        return $this->redirect($this->generateUrl('app_convocation', ['id' => $rencontre->getId()]) . '#reponses');
    }

    /**
     * Toutes les réponses du tableau enregistrées d'un coup (seules celles qui changent sont notées, au nom de l'éducateur) ;
     * « tous présents » : chaque joueur encore sans réponse est noté présent.
     */
    #[Route('/reponses', name: 'app_convocation_reponses', methods: ['POST'])]
    public function reponses(Request $request, Rencontre $rencontre, ConvocationRepository $convocations): Response
    {
        $convocation = $convocations->pour($rencontre);
        if (!$convocation || !$this->isCsrfTokenValid('reponses-convocation' . $rencontre->getId(), $request->getPayload()->getString('_token'))) {
            throw $this->createAccessDeniedException();
        }
        /** @var Utilisateur $moi */
        $moi = $this->getUser();
        $tousPresents = 'tous_presents' === $request->getPayload()->getString('action');
        $reponses = $request->getPayload()->all('reponse');
        $motifs = $request->getPayload()->all('motif');
        $changees = 0;
        foreach ($convocation->getConvoques() as $c) {
            if ($tousPresents) {
                [$reponse, $motif] = null === $c->getReponse() ? [Convoque::PRESENT, ''] : [$c->getReponse(), (string) $c->getMotif()];
            } elseif (\array_key_exists($c->getId(), $reponses)) {
                $reponse = \in_array($reponses[$c->getId()], [Convoque::PRESENT, Convoque::ABSENT], true) ? $reponses[$c->getId()] : null;
                $motif = Convoque::ABSENT === $reponse ? trim((string) ($motifs[$c->getId()] ?? '')) : '';
            } else {
                continue;
            }
            if ($reponse !== $c->getReponse() || ($motif ?: null) !== ($c->getMotif() ?: null)) {
                $c->repondre($reponse, $motif, 'Éducateur : ' . $moi);
                ++$changees;
            }
        }
        $this->em->flush();
        $this->addFlash($changees ? 'succes' : 'info', $changees
            ? \sprintf('%d réponse%s enregistrée%2$s.', $changees, $changees > 1 ? 's' : '')
            : 'Aucune réponse n\'a changé.');

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
     * les lignes restées telles que le préremplissage les a créées. Un poste, une note, un carton,
     * une appréciation, un but ou un remplacement : la ligne est gardée. (Le numéro ne compte pas :
     * il est donné d'office, 1, 2, 3…, au préremplissage.)
     */
    /** @param list<Joueur>|null $seulement joueurs à retirer (tous les convoqués par défaut) */
    private function retirerDeLaFeuille(Rencontre $rencontre, Convocation $convocation, ?array $seulement = null): int
    {
        $convoques = array_map(fn (Joueur $j) => $j->getId(), $seulement ?? $convocation->getJoueurs());
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
            $vierge = '' === trim((string) $p->getPoste()) && null === $p->getNote()
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
