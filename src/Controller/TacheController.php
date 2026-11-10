<?php

namespace App\Controller;

use App\Entity\Tache;
use App\Entity\Utilisateur;
use App\Form\TacheType;
use App\Repository\CategorieRepository;
use App\Repository\TacheRepository;
use App\Repository\UtilisateurRepository;
use App\Service\AvisTaches;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Tâches confiées aux membres de l'encadrement. Tout l'encadrement les voit et peut en confier ;
 * la personne chargée, celle qui l'a confiée et l'administration la modifient.
 */
#[Route('/taches')]
class TacheController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly AvisTaches $avis,
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
            ->leftJoin('t.joueur', 'j')->addSelect('j')
            ->addSelect('CASE WHEN t.echeance IS NULL THEN 1 ELSE 0 END AS HIDDEN sans_echeance');
        TacheRepository::visibles($qb, $moi);
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
                'toutes' => $taches->compterOuvertes($moi),
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
        if ($retour = $this->siInvisible($tache)) {
            return $retour;
        }
        return $this->render('tache/voir.html.twig', [
            't' => $tache,
            'peut_modifier' => $this->peutModifier($tache),
            'peut_supprimer' => $this->peutSupprimer($tache),
        ]);
    }

    #[Route('/{id}/modifier', name: 'app_tache_modifier', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function modifier(Request $request, Tache $tache): Response
    {
        if ($retour = $this->siInvisible($tache)) {
            return $retour;
        }
        $this->verifier($this->peutModifier($tache));

        return $this->formulaire($request, $tache, 'Modifier la tâche', false);
    }

    /** Boutons « Commencer », « Marquer comme faite », « Rouvrir ». */
    #[Route('/{id}/statut', name: 'app_tache_statut', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function statut(Request $request, Tache $tache): Response
    {
        if ($retour = $this->siInvisible($tache)) {
            return $retour;
        }
        $this->verifier($this->peutModifier($tache));
        $statut = $request->getPayload()->getString('statut');
        if ($this->isCsrfTokenValid('statut-tache' . $tache->getId(), $request->getPayload()->getString('_token'))
            && \array_key_exists($statut, Tache::STATUTS)) {
            $avant = $tache->getStatut();
            $tache->setStatut($statut);
            $this->em->flush();
            if ('faite' === $statut && 'faite' !== $avant) {
                $this->avis->faite($tache, $this->moi());
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
        if ($retour = $this->siInvisible($tache)) {
            return $retour;
        }
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
        $joueurAvant = $tache->getJoueur();
        $statutAvant = $tache->getStatut();
        $form = $this->createForm(TacheType::class, $tache, ['creation' => $creation]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->em->persist($tache);
            $this->em->flush();
            $message = $creation ? 'Tâche confiée à ' . $tache->getChargeDe() . '.' : 'Tâche enregistrée.';
            if ($tache->getResponsable() && $tache->getResponsable() !== $responsableAvant && $this->avis->responsable($tache, $this->moi())) {
                $message .= $tache->getResponsable() === $this->moi()
                    ? ' Un rappel vous est envoyé par e-mail.'
                    : ' ' . $tache->getResponsable()->getPrenom() . ' est prévenu(e) par e-mail.';
            }
            if ($tache->getJoueur() && $tache->getJoueur() !== $joueurAvant) {
                $prevenus = $this->avis->famille($tache, $this->moi());
                $message .= $prevenus
                    ? ' Famille prévenue par e-mail (' . $prevenus . ' ' . ($prevenus > 1 ? 'comptes' : 'compte') . ').'
                    : ' La famille n\'a pas de compte actif : prévenez-la vous-même.';
            }
            if ($tache->isFaite() && 'faite' !== $statutAvant) {
                $this->avis->faite($tache, $this->moi());
            }
            $this->addFlash('succes', $message);

            return $this->redirectToRoute('app_tache_voir', ['id' => $tache->getId()]);
        }

        return $this->render('tache/formulaire.html.twig', ['form' => $form, 't' => $tache, 'titre' => $titre]);
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

    /**
     * Tâche privée d'autres personnes (administration comprise) : retour à la liste avec un message,
     * par exemple depuis une page restée ouverte avant que la tâche devienne privée.
     */
    private function siInvisible(Tache $tache): ?Response
    {
        if ($tache->estVisiblePar($this->moi())) {
            return null;
        }
        $this->addFlash('info', 'Cette tâche est privée : seules la personne qui l\'a confiée et la personne chargée peuvent la voir.');

        return $this->redirectToRoute('app_tache_index');
    }

    private function verifier(bool $autorise): void
    {
        if (!$autorise) {
            throw $this->createAccessDeniedException();
        }
    }

    private function moi(): Utilisateur
    {
        $u = $this->getUser();
        \assert($u instanceof Utilisateur);

        return $u;
    }
}
