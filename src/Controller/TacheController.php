<?php

namespace App\Controller;

use App\Entity\Tache;
use App\Entity\Utilisateur;
use App\Form\TacheType;
use App\Repository\CategorieRepository;
use App\Repository\TacheRepository;
use App\Repository\UtilisateurRepository;
use App\Service\CourrielClub;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Tâches confiées aux membres de l'encadrement. Tout l'encadrement les voit et peut en confier ;
 * la personne chargée, celle qui l'a confiée et l'administration la modifient.
 */
#[Route('/taches')]
class TacheController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly MailerInterface $mailer,
        private readonly CourrielClub $courriel,
        private readonly LoggerInterface $logger,
    ) {
    }

    #[Route('', name: 'app_tache_index', methods: ['GET'])]
    public function index(Request $request, TacheRepository $taches, CategorieRepository $categories, UtilisateurRepository $utilisateurs): Response
    {
        $moi = $this->moi();
        $vue = \in_array($v = $request->query->getString('vue', 'miennes'), ['miennes', 'donnees', 'toutes'], true) ? $v : 'miennes';
        $statut = \in_array($s = $request->query->getString('statut', 'ouvertes'), ['ouvertes', 'faites', 'toutes'], true) ? $s : 'ouvertes';
        $filtres = [
            'vue' => $vue,
            'statut' => $statut,
            'categorie' => $request->query->getString('categorie') ?: null,
            'responsable' => 'toutes' === $vue ? ($request->query->getInt('responsable') ?: null) : null,
        ];

        $qb = $taches->createQueryBuilder('t')
            ->leftJoin('t.categorie', 'c')->addSelect('c')
            ->leftJoin('t.responsable', 'r')->addSelect('r')
            ->addSelect('CASE WHEN t.echeance IS NULL THEN 1 ELSE 0 END AS HIDDEN sans_echeance');
        match ($vue) {
            'miennes' => $qb->andWhere('t.responsable = :moi')->setParameter('moi', $moi),
            'donnees' => $qb->andWhere('t.creePar = :moi')->setParameter('moi', $moi),
            default => null,
        };
        match ($statut) {
            'ouvertes' => $qb->andWhere('t.statut != :faite')->setParameter('faite', 'faite')
                ->orderBy('t.urgente', 'DESC')->addOrderBy('sans_echeance')->addOrderBy('t.echeance')->addOrderBy('t.id'),
            'faites' => $qb->andWhere('t.statut = :faite')->setParameter('faite', 'faite')->orderBy('t.faiteLe', 'DESC'),
            default => $qb->orderBy('t.statut')->addOrderBy('sans_echeance')->addOrderBy('t.echeance')->addOrderBy('t.id'),
        };
        if ('club' === $filtres['categorie']) {
            $qb->andWhere('t.categorie IS NULL');
        } elseif ($filtres['categorie']) {
            $qb->andWhere('t.categorie = :categorie')->setParameter('categorie', (int) $filtres['categorie']);
        }
        if ($filtres['responsable']) {
            $qb->andWhere('t.responsable = :responsable')->setParameter('responsable', $filtres['responsable']);
        }

        return $this->render('tache/index.html.twig', [
            'taches' => $qb->getQuery()->getResult(),
            'filtres' => $filtres,
            'categories' => $categories->findBy([], ['ordre' => 'ASC', 'nom' => 'ASC']),
            'personnes' => 'toutes' === $vue ? $utilisateurs->findBy(['famille' => false], ['nom' => 'ASC', 'prenom' => 'ASC']) : [],
            'compteurs' => [
                'miennes' => $taches->compterOuvertesDe($moi),
                'donnees' => (int) $taches->createQueryBuilder('t')->select('COUNT(t.id)')
                    ->where('t.creePar = :moi AND t.statut != :faite')->setParameter('moi', $moi)->setParameter('faite', 'faite')
                    ->getQuery()->getSingleScalarResult(),
            ],
        ]);
    }

    #[Route('/nouvelle', name: 'app_tache_nouvelle', methods: ['GET', 'POST'])]
    public function nouvelle(Request $request, CategorieRepository $categories, UtilisateurRepository $utilisateurs): Response
    {
        $tache = (new Tache())->setCreePar($this->moi());
        if ($c = $request->query->getInt('categorie')) {
            $tache->setCategorie($categories->find($c));
        }
        if ($r = $request->query->getInt('responsable')) {
            $tache->setResponsable($utilisateurs->findOneBy(['id' => $r, 'famille' => false]));
        }

        return $this->formulaire($request, $tache, 'Nouvelle tâche', true);
    }

    #[Route('/{id}', name: 'app_tache_voir', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function voir(Tache $tache): Response
    {
        return $this->render('tache/voir.html.twig', [
            't' => $tache,
            'peut_modifier' => $this->peutModifier($tache),
            'peut_supprimer' => $this->peutSupprimer($tache),
        ]);
    }

    #[Route('/{id}/modifier', name: 'app_tache_modifier', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function modifier(Request $request, Tache $tache): Response
    {
        $this->verifier($this->peutModifier($tache));

        return $this->formulaire($request, $tache, 'Modifier la tâche', false);
    }

    /** Boutons « Commencer », « Marquer comme faite », « Rouvrir ». */
    #[Route('/{id}/statut', name: 'app_tache_statut', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function statut(Request $request, Tache $tache): Response
    {
        $this->verifier($this->peutModifier($tache));
        $statut = $request->getPayload()->getString('statut');
        if ($this->isCsrfTokenValid('statut-tache' . $tache->getId(), $request->getPayload()->getString('_token'))
            && \array_key_exists($statut, Tache::STATUTS)) {
            $avant = $tache->getStatut();
            $tache->setStatut($statut);
            $this->em->flush();
            if ('faite' === $statut && 'faite' !== $avant) {
                $this->prevenirFaite($tache);
            }
            $this->addFlash('succes', 'Tâche « ' . $tache->getObjet() . ' » : ' . mb_strtolower($tache->getStatutLibelle()) . '.');
        }

        $retour = $request->getPayload()->getString('retour');

        return str_starts_with($retour, '/') && !str_starts_with($retour, '//')
            ? $this->redirect($retour)
            : $this->redirectToRoute('app_tache_voir', ['id' => $tache->getId()]);
    }

    #[Route('/{id}/supprimer', name: 'app_tache_supprimer', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function supprimer(Request $request, Tache $tache): Response
    {
        $this->verifier($this->peutSupprimer($tache));
        if ($this->isCsrfTokenValid('supprimer-tache' . $tache->getId(), $request->getPayload()->getString('_token'))) {
            $this->em->remove($tache);
            $this->em->flush();
            $this->addFlash('succes', 'Tâche supprimée.');
        }

        return $this->redirectToRoute('app_tache_index');
    }

    private function formulaire(Request $request, Tache $tache, string $titre, bool $creation): Response
    {
        $responsableAvant = $tache->getResponsable();
        $statutAvant = $tache->getStatut();
        $form = $this->createForm(TacheType::class, $tache, ['creation' => $creation]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->em->persist($tache);
            $this->em->flush();
            $message = $creation ? 'Tâche confiée à ' . $this->nom($tache->getResponsable()) . '.' : 'Tâche enregistrée.';
            if ($tache->getResponsable() !== $responsableAvant && $this->prevenirResponsable($tache)) {
                $message .= ' ' . $tache->getResponsable()->getPrenom() . ' est prévenu(e) par e-mail.';
            }
            if ($tache->isFaite() && 'faite' !== $statutAvant) {
                $this->prevenirFaite($tache);
            }
            $this->addFlash('succes', $message);

            return $this->redirectToRoute('app_tache_voir', ['id' => $tache->getId()]);
        }

        return $this->render('tache/formulaire.html.twig', ['form' => $form, 't' => $tache, 'titre' => $titre]);
    }

    /** E-mail à la personne chargée, sauf si elle se confie la tâche à elle-même. */
    private function prevenirResponsable(Tache $tache): bool
    {
        $responsable = $tache->getResponsable();
        if (!$responsable || $responsable === $this->moi()) {
            return false;
        }

        return $this->envoyer($tache, $responsable, 'Nouvelle tâche : ' . $tache->getObjet(), [
            'titre' => 'Une tâche vous est confiée',
            'salutation' => 'Bonjour ' . $responsable->getPrenom() . ',',
            'paragraphes' => [$this->nom($this->moi()) . ' vous confie la tâche suivante.'],
            'encadre' => $tache->getObjet(),
            'details' => [
                'Catégorie' => $tache->getCategorie()?->getNom() ?? 'Tout le club',
                'À faire pour le' => $tache->getEcheance()?->format('d/m/Y'),
                'Urgente' => $tache->isUrgente() ? 'oui' : null,
                'Détails' => $tache->getDescription(),
            ],
            'bouton' => ['libelle' => 'Voir la tâche', 'url' => $this->generateUrl('app_tache_voir', ['id' => $tache->getId()], UrlGeneratorInterface::ABSOLUTE_URL)],
        ]);
    }

    /** E-mail à la personne qui a confié la tâche quand quelqu'un d'autre la termine. */
    private function prevenirFaite(Tache $tache): void
    {
        $auteur = $tache->getCreePar();
        if (!$auteur || $auteur === $this->moi()) {
            return;
        }
        $this->envoyer($tache, $auteur, 'Tâche faite : ' . $tache->getObjet(), [
            'titre' => 'Tâche faite',
            'salutation' => 'Bonjour ' . $auteur->getPrenom() . ',',
            'paragraphes' => [$this->nom($this->moi()) . ' a terminé la tâche que vous aviez confiée.'],
            'encadre' => $tache->getObjet(),
            'details' => ['Compte rendu' => $tache->getCompteRendu()],
            'bouton' => ['libelle' => 'Voir la tâche', 'url' => $this->generateUrl('app_tache_voir', ['id' => $tache->getId()], UrlGeneratorInterface::ABSOLUTE_URL)],
        ]);
    }

    /** @param array<string, mixed> $contenu */
    private function envoyer(Tache $tache, Utilisateur $a, string $sujet, array $contenu): bool
    {
        try {
            $this->mailer->send($this->courriel->message($tache->getClub(), (string) $a->getEmail(), $sujet, $contenu));

            return true;
        } catch (TransportExceptionInterface $e) {
            $this->logger->error('Tâche : e-mail non envoyé à {email} : {erreur}', ['email' => $a->getEmail(), 'erreur' => $e->getMessage()]);

            return false;
        }
    }

    private function peutModifier(Tache $tache): bool
    {
        $moi = $this->moi();

        return $this->isGranted('ROLE_ADMIN') || $tache->getCreePar() === $moi || $tache->getResponsable() === $moi;
    }

    private function peutSupprimer(Tache $tache): bool
    {
        return $this->isGranted('ROLE_ADMIN') || $tache->getCreePar() === $this->moi();
    }

    private function verifier(bool $autorise): void
    {
        if (!$autorise) {
            throw $this->createAccessDeniedException();
        }
    }

    private function nom(?Utilisateur $u): string
    {
        return $u ? $u->getPrenom() . ' ' . $u->getNom() : '?';
    }

    private function moi(): Utilisateur
    {
        $u = $this->getUser();
        \assert($u instanceof Utilisateur);

        return $u;
    }
}
