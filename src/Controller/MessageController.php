<?php

namespace App\Controller;

use App\Entity\Categorie;
use App\Entity\Convocation;
use App\Entity\Convoque;
use App\Entity\Joueur;
use App\Entity\Message;
use App\Entity\MessageReponse;
use App\Entity\Utilisateur;
use App\Repository\CategorieRepository;
use App\Repository\ConvocationRepository;
use App\Repository\JoueurRepository;
use App\Repository\MessageRepository;
use App\Repository\UtilisateurRepository;
use App\Security\ActiviteVoter;
use App\Security\JoueurVoter;
use App\Service\AccesFamille;
use App\Service\Messagerie;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Validator\Constraints\Length;

/**
 * Messagerie interne, côté encadrement : écrire aux familles d'une catégorie, des convoqués d'un match
 * (tous, présents, sans réponse) ou de joueurs choisis ; tout le club pour l'administrateur.
 * Chaque famille répond dans un fil privé avec l'encadrement.
 */
#[Route('/messages')]
class MessageController extends AbstractController
{
    private const FILTRES_CONVOCATION = ['tous' => 'Tous les convoqués', 'presents' => 'Les présents', 'sans_reponse' => 'Ceux qui n\'ont pas répondu', 'absents' => 'Les absents'];

    public function __construct(
        private readonly MessageRepository $messages,
        private readonly JoueurVoter $joueurVoter,
        private readonly EntityManagerInterface $em,
    ) {
    }

    #[Route('', name: 'app_message_index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $page = max(1, $request->query->getInt('page', 1));

        return $this->render('message/index.html.twig', [
            // Messages reçus de collègues (première page seulement).
            'recus' => 1 === $page ? $this->messages->recus($this->moi()) : [],
            'messages' => $this->messages->pourEncadrement($this->moi(), $this->secteurs(), $page),
            'page' => $page,
            'par_page' => MessageRepository::PAR_PAGE,
        ]);
    }

    #[Route('/nouveau', name: 'app_message_nouveau', methods: ['GET', 'POST'])]
    public function nouveau(Request $request, CategorieRepository $categories, ConvocationRepository $convocations, JoueurRepository $joueurs, Messagerie $messagerie, AccesFamille $acces, UtilisateurRepository $utilisateurs): Response
    {
        // Collègues : tous les comptes de l'encadrement du club (éducateurs, dirigeants, médecin, kiné...), sauf soi.
        $collegues = array_values(array_filter(
            $utilisateurs->findBy(['famille' => false], ['nom' => 'ASC', 'prenom' => 'ASC']),
            fn (Utilisateur $u) => $u !== $this->moi(),
        ));
        $mesCategories = $this->categoriesGerees($categories);
        $mesConvocations = $this->convocationsRecentes($convocations);
        $mesJoueurs = $mesCategories ? $joueurs->createQueryBuilder('j')->join('j.categorie', 'c')
            ->andWhere('j.ancien = false')->andWhere('c IN (:c)')->setParameter('c', $mesCategories)
            ->orderBy('c.ordre')->addOrderBy('j.nom')->addOrderBy('j.prenom')->getQuery()->getResult() : [];
        $cibles = Message::CIBLES;
        if (!$this->isGranted('ROLE_ADMIN')) {
            unset($cibles['club']);
        }
        if (!$mesConvocations) {
            unset($cibles['convocation']);
        }
        if (!$collegues) {
            unset($cibles['encadrement']);
        }

        // Préremplissage depuis un match (« Écrire aux convoqués »), une catégorie ou la fiche d'un joueur.
        $donnees = ['cible' => 'categorie', 'filtre' => 'tous', 'email' => true, 'important' => false, 'joueurs' => []];
        $q = $request->query;
        if ($q->getInt('rencontre')) {
            foreach ($mesConvocations as $c) {
                if ($c->getRencontre()->getId() === $q->getInt('rencontre')) {
                    $donnees = ['cible' => 'convocation', 'convocation' => $c] + $donnees;
                }
            }
        } elseif ($q->getInt('joueur')) {
            $donnees = ['cible' => 'joueurs', 'joueurs' => array_values(array_filter($mesJoueurs, fn (Joueur $j) => $j->getId() === $q->getInt('joueur')))] + $donnees;
        } elseif ($q->getInt('personne')) {
            $donnees = ['cible' => 'encadrement', 'personnes' => array_values(array_filter($collegues, fn (Utilisateur $u) => $u->getId() === $q->getInt('personne')))] + $donnees;
        }
        $donnees['personnes'] ??= [];
        $donnees['categorie'] ??= current(array_filter($mesCategories, fn (Categorie $c) => $c->getId() === $q->getInt('categorie'))) ?: null;

        $form = $this->createFormBuilder($donnees)
            ->add('cible', ChoiceType::class, ['label' => 'Destinataires', 'choices' => array_flip($cibles), 'expanded' => true])
            ->add('categorie', EntityType::class, ['class' => Categorie::class, 'choices' => $mesCategories, 'label' => 'Catégorie', 'required' => false, 'placeholder' => '— Choisir —'])
            ->add('convocation', EntityType::class, [
                'class' => Convocation::class, 'choices' => $mesConvocations, 'label' => 'Match', 'required' => false, 'placeholder' => '— Choisir —',
                'choice_label' => fn (Convocation $c) => $this->libelleMatch($c),
            ])
            ->add('filtre', ChoiceType::class, ['label' => 'Parmi les convoqués', 'choices' => array_flip(self::FILTRES_CONVOCATION)])
            ->add('joueurs', EntityType::class, [
                'class' => Joueur::class, 'choices' => $mesJoueurs, 'multiple' => true, 'expanded' => true, 'label' => 'Joueurs', 'required' => false,
                'choice_label' => fn (Joueur $j) => $j->getNom() . ' ' . $j->getPrenom(),
                'group_by' => fn (Joueur $j) => (string) $j->getCategorie(),
            ])
            ->add('personnes', EntityType::class, [
                'class' => Utilisateur::class, 'choices' => $collegues, 'multiple' => true, 'expanded' => true, 'label' => 'Membres de l\'encadrement', 'required' => false,
                'choice_label' => fn (Utilisateur $u) => trim($u->getPrenom() . ' ' . $u->getNom()),
            ])
            ->add('sujet', TextType::class, ['label' => 'Objet', 'attr' => ['maxlength' => 120, 'placeholder' => 'ex. Tournoi de samedi : covoiturage'], 'constraints' => [new Length(max: 120)]])
            ->add('corps', TextareaType::class, ['label' => 'Message', 'attr' => ['rows' => 8], 'constraints' => [new Length(max: 5000)]])
            ->add('important', CheckboxType::class, ['label' => 'Information importante (affichée en tête de l\'espace famille tant qu\'elle n\'est pas lue)', 'required' => false])
            ->add('email', CheckboxType::class, ['label' => 'Prévenir aussi par e-mail', 'required' => false])
            ->getForm();
        $form->handleRequest($request);

        if ($form->isSubmitted()) {
            $d = $form->getData();
            [$destinataires, $libelle, $categorie, $rencontre, $personnes] = $this->destinataires($d, $form, $joueurs) + [4 => []];
            $message = (new Message())->setAuteur($this->moi())->setSujet($d['sujet'])->setCorps($d['corps'])
                ->setImportant((bool) $d['important'])->setCible($d['cible'])->setCibleLibelle($libelle)
                ->setCategorie($categorie)->setRencontre($rencontre);
            if ('' === trim((string) $d['sujet'])) {
                $form->get('sujet')->addError(new FormError('Indiquez l\'objet du message.'));
            }
            if ('' === trim((string) $d['corps'])) {
                $form->get('corps')->addError(new FormError('Le message est vide.'));
            }
            if ($form->isValid()) {
                $r = $messagerie->envoyer($message, $destinataires, (bool) $d['email'], $personnes);
                $nb = \count($destinataires) ?: \count($personnes);
                $qui = $destinataires ? ($nb > 1 ? 'joueurs' : 'joueur') : ($nb > 1 ? 'personnes' : 'personne');
                $this->addFlash('succes', \sprintf('Message envoyé à %d %s%s.', $nb, $qui,
                    $r['emails'] ? \sprintf(' (%d e-mail%s)', $r['emails'], $r['emails'] > 1 ? 's' : '') : ''));
                if ($r['echecs']) {
                    $this->addFlash('erreur', 'E-mail impossible vers : ' . implode(', ', $r['echecs']) . '.');
                }

                return $this->redirectToRoute('app_message_voir', ['id' => $message->getId()]);
            }
        }

        return $this->render('message/nouveau.html.twig', [
            'form' => $form,
            'email_actif' => $acces->emailActif(),
        ]);
    }

    #[Route('/{id}', name: 'app_message_voir', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function voir(Message $message, Messagerie $messagerie, AccesFamille $acces): Response
    {
        $this->verifier($message);
        // Message reçu d'un collègue : sa conversation avec l'expéditeur seulement.
        if ($message->estDestinataire($this->moi()) && $message->getAuteur() !== $this->moi()) {
            return $this->voirRecu($message);
        }
        // Fils de réponse : un par compte famille qui a écrit (ou à qui l'encadrement a répondu).
        $fils = [];
        foreach ($message->getReponses() as $r) {
            $fils[$r->getFamille()->getId()] ??= ['famille' => $r->getFamille(), 'reponses' => [], 'joueurs' => []];
            $fils[$r->getFamille()->getId()]['reponses'][] = $r;
        }
        $lignes = [];
        foreach ($message->getJoueurs() as $j) {
            $dest = $message->destinatairesDe($j);
            foreach ($dest as $d) {
                if ($d->getCompte() && isset($fils[$d->getCompte()->getId()])) {
                    $fils[$d->getCompte()->getId()]['joueurs'][] = $j;
                }
            }
            $lignes[] = ['joueur' => $j, 'destinataires' => array_filter($dest, fn ($d) => null !== $d->getCompte())];
        }
        $html = $this->render('message/voir.html.twig', [
            'message' => $message,
            'lignes' => $lignes,
            'fils' => $fils,
            'sans_compte' => array_values(array_filter($lignes, fn ($l) => !$l['destinataires'] || !array_filter($l['destinataires'], fn ($d) => null !== $d->getCompte()->getPassword()))),
            'texte_a_copier' => $messagerie->texteACopier($message),
            'email_actif' => $acces->emailActif(),
            'peut_supprimer' => $message->getAuteur() === $this->moi() || $this->isGranted('ROLE_ADMIN'),
        ]);
        // Réponses des familles affichées : elles sont lues.
        foreach ($message->getReponses() as $r) {
            if (!$r->isDeLEncadrement()) {
                $r->marquerLue();
            }
        }
        $this->em->flush();

        return $html;
    }

    /** Message d'un collègue, vu par son destinataire : lu en l'ouvrant, réponse dans son fil privé. */
    private function voirRecu(Message $message): Response
    {
        $moi = $this->moi();
        $fil = $message->fil($moi);
        $html = $this->render('message/recu.html.twig', ['message' => $message, 'fil' => $fil]);
        foreach ($message->getDestinataires() as $d) {
            if ($d->getCompte() === $moi) {
                $d->marquerLu();
            }
        }
        foreach ($fil as $r) {
            if ($r->isDeLEncadrement()) {
                $r->marquerLue();
            }
        }
        $this->em->flush();

        return $html;
    }

    #[Route('/{id}/repondre/{famille}', name: 'app_message_repondre', requirements: ['id' => '\d+', 'famille' => '\d+'], methods: ['POST'])]
    public function repondre(Request $request, Message $message, int $famille, UtilisateurRepository $utilisateurs, Messagerie $messagerie): Response
    {
        $this->verifier($message);
        $compte = $utilisateurs->find($famille);
        // Un destinataire ne répond que dans son propre fil ; l'expéditeur (ou un collègue qui voit le message), dans tous.
        $voitTout = $this->messages->estVisible($message, $this->moi(), $this->secteurs());
        if (!$compte || !$message->estDestinataire($compte) || (!$voitTout && $compte !== $this->moi()) || !$this->isCsrfTokenValid('repondre-message' . $message->getId() . '-' . $famille, $request->getPayload()->getString('_token'))) {
            throw $this->createAccessDeniedException();
        }
        $texte = trim($request->getPayload()->getString('corps'));
        if ('' !== $texte) {
            $reponse = new MessageReponse($message, $compte, $this->moi(), mb_substr($texte, 0, 3000));
            $this->em->persist($reponse);
            $this->em->flush();
            $messagerie->avertir($reponse);
            $this->addFlash('succes', 'Réponse envoyée à ' . ($compte === $this->moi() ? $message->getAuteurNom() : ($compte->isFamille() ? $compte->getEmail() : trim($compte->getPrenom() . ' ' . $compte->getNom()))) . '.');
        }

        return $this->redirect($this->generateUrl('app_message_voir', ['id' => $message->getId()]) . '#fil-' . $famille);
    }

    #[Route('/{id}/supprimer', name: 'app_message_supprimer', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function supprimer(Request $request, Message $message): Response
    {
        $this->verifier($message);
        if (($message->getAuteur() === $this->moi() || $this->isGranted('ROLE_ADMIN'))
            && $this->isCsrfTokenValid('supprimer-message' . $message->getId(), $request->getPayload()->getString('_token'))) {
            $this->em->remove($message);
            $this->em->flush();
            $this->addFlash('succes', 'Message supprimé : les familles ne le voient plus.');
        }

        return $this->redirectToRoute('app_message_index');
    }

    /**
     * Joueurs destinataires, libellé, catégorie et match, selon la cible choisie ; erreurs ajoutées au formulaire.
     *
     * @param array<string, mixed> $d
     *
     * @return array{0: list<Joueur>, 1: string, 2: ?Categorie, 3: ?\App\Entity\Rencontre}
     */
    private function destinataires(array $d, FormInterface $form, JoueurRepository $joueurs): array
    {
        switch ($d['cible']) {
            case 'categorie':
                if (!$d['categorie']) {
                    $form->get('categorie')->addError(new FormError('Choisissez la catégorie.'));

                    return [[], '', null, null];
                }
                $liste = $joueurs->findBy(['categorie' => $d['categorie'], 'ancien' => false], ['nom' => 'ASC', 'prenom' => 'ASC']);

                return [$liste, 'Catégorie ' . $d['categorie']->getNom(), $d['categorie'], null];
            case 'convocation':
                /** @var ?Convocation $c */
                $c = $d['convocation'];
                if (!$c) {
                    $form->get('convocation')->addError(new FormError('Choisissez le match.'));

                    return [[], '', null, null];
                }
                $filtre = \array_key_exists($d['filtre'], self::FILTRES_CONVOCATION) ? $d['filtre'] : 'tous';
                $convoques = array_filter($c->getConvoques(), fn (Convoque $x) => match ($filtre) {
                    'presents' => Convoque::PRESENT === $x->getReponse(),
                    'absents' => Convoque::ABSENT === $x->getReponse(),
                    'sans_reponse' => null === $x->getReponse(),
                    default => true,
                });
                if (!$convoques) {
                    $form->get('filtre')->addError(new FormError('Aucun convoqué ne correspond à ce choix.'));
                }
                $libelle = ('tous' === $filtre ? 'Convoqués' : self::FILTRES_CONVOCATION[$filtre]) . ' – ' . $this->libelleMatch($c);

                return [array_values(array_map(fn (Convoque $x) => $x->getJoueur(), $convoques)), $libelle, $c->getRencontre()->getCategorie(), $c->getRencontre()];
            case 'joueurs':
                $liste = array_values($d['joueurs'] instanceof \Traversable ? iterator_to_array($d['joueurs']) : (array) $d['joueurs']);
                if (!$liste) {
                    $form->get('joueurs')->addError(new FormError('Cochez au moins un joueur.'));

                    return [[], '', null, null];
                }
                $cats = array_unique(array_map(fn (Joueur $j) => $j->getCategorie()?->getId(), $liste));
                $libelle = 1 === \count($liste) ? $liste[0]->getNomComplet() : \sprintf('%d joueurs : %s', \count($liste), implode(', ', array_map(fn (Joueur $j) => $j->getPrenom() . ' ' . $j->getNom(), $liste)));

                return [$liste, $libelle, 1 === \count($cats) ? $liste[0]->getCategorie() : null, null];
            case 'encadrement':
                $personnes = array_values($d['personnes'] instanceof \Traversable ? iterator_to_array($d['personnes']) : (array) $d['personnes']);
                if (!$personnes) {
                    $form->get('personnes')->addError(new FormError('Cochez au moins une personne.'));

                    return [[], '', null, null, []];
                }
                $libelle = implode(', ', array_map(fn (Utilisateur $u) => trim($u->getPrenom() . ' ' . $u->getNom()), $personnes));

                return [[], $libelle, null, null, $personnes];
            case 'club':
                if ($this->isGranted('ROLE_ADMIN')) {
                    return [$joueurs->findBy(['ancien' => false], ['nom' => 'ASC']), 'Tout le club', null, null];
                }
        }
        $form->get('cible')->addError(new FormError('Choisissez les destinataires.'));

        return [[], '', null, null];
    }

    /** @return list<Categorie> catégories dont l'utilisateur gère les joueurs */
    private function categoriesGerees(CategorieRepository $categories): array
    {
        if ($this->isGranted('ROLE_ADMIN')) {
            return $categories->findBy([], ['ordre' => 'ASC', 'nom' => 'ASC']);
        }

        return $categories->findBy(['secteur' => $this->joueurVoter->secteursModifiables() ?: ['-']], ['ordre' => 'ASC', 'nom' => 'ASC']);
    }

    /** @return list<Convocation> convocations des matchs des 15 derniers jours et à venir, gérées par l'utilisateur */
    private function convocationsRecentes(ConvocationRepository $convocations): array
    {
        $liste = $convocations->createQueryBuilder('c')->join('c.rencontre', 'r')->addSelect('r')
            ->andWhere('r.date >= :depuis')->setParameter('depuis', new \DateTimeImmutable('-15 days'), Types::DATE_IMMUTABLE)
            ->orderBy('r.date', 'ASC')->getQuery()->getResult();

        return array_values(array_filter($liste, fn (Convocation $c) => $this->isGranted(ActiviteVoter::GERER, $c->getRencontre())));
    }

    private function libelleMatch(Convocation $c): string
    {
        $r = $c->getRencontre();

        return \sprintf('%s contre %s, %s', $r->getEquipeAffichee(), $r->getAdversaire(), $r->getDate()?->format('d/m'));
    }

    private function verifier(Message $message): void
    {
        if (!$this->messages->estVisible($message, $this->moi(), $this->secteurs()) && !$message->estDestinataire($this->moi())) {
            throw $this->createAccessDeniedException();
        }
    }

    /** @return list<string>|null secteurs visibles, null pour tout le club (administrateur) */
    private function secteurs(): ?array
    {
        return $this->isGranted('ROLE_ADMIN') ? null : $this->joueurVoter->secteursModifiables();
    }

    private function moi(): Utilisateur
    {
        /** @var Utilisateur $moi */
        $moi = $this->getUser();

        return $moi;
    }
}
