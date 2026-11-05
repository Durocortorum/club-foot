<?php

namespace App\Controller;

use App\Entity\Categorie;
use App\Entity\Joueur;
use App\Entity\Utilisateur;
use App\Repository\CategorieRepository;
use App\Repository\JoueurRepository;
use App\Security\JoueurVoter;
use App\Service\AccesFamille;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Accès famille, côté encadrement : inviter les joueurs et leurs parents à créer leur compte,
 * depuis la fiche d'un joueur ou pour toute une catégorie.
 */
class FamilleController extends AbstractController
{
    public function __construct(
        private readonly AccesFamille $acces,
        private readonly JoueurVoter $voter,
    ) {
    }

    #[Route('/familles', name: 'app_famille_index', methods: ['GET'])]
    public function index(Request $request, CategorieRepository $categories, JoueurRepository $joueurs): Response
    {
        $proposees = $categories->findBy(['secteur' => $this->voter->secteursModifiables()], ['ordre' => 'ASC', 'nom' => 'ASC']);
        if (!$proposees) {
            throw $this->createAccessDeniedException();
        }
        $categorie = $this->categorie($request->query->getInt('categorie'), $proposees) ?? $proposees[0];

        $lignes = [];
        foreach ($joueurs->findBy(['categorie' => $categorie, 'ancien' => false], ['nom' => 'ASC', 'prenom' => 'ASC']) as $j) {
            $lignes[] = ['joueur' => $j] + $this->etat($j);
        }

        return $this->render('famille/index.html.twig', [
            'categories' => $proposees,
            'categorie' => $categorie,
            'lignes' => $lignes,
            'a_inviter' => array_sum(array_map(fn (array $l) => \count($l['a_inviter']), $lignes)),
            'email_actif' => $this->acces->emailActif(),
        ]);
    }

    /** Invite toutes les adresses de la catégorie qui n'ont pas encore de compte. */
    #[Route('/familles/inviter', name: 'app_famille_inviter_categorie', methods: ['POST'])]
    public function inviterCategorie(Request $request, CategorieRepository $categories, JoueurRepository $joueurs): Response
    {
        $proposees = $categories->findBy(['secteur' => $this->voter->secteursModifiables()]);
        $categorie = $this->categorie($request->getPayload()->getInt('categorie'), $proposees) ?? throw $this->createAccessDeniedException();
        if (!$this->isCsrfTokenValid('inviter-familles' . $categorie->getId(), $request->getPayload()->getString('_token'))) {
            throw $this->createAccessDeniedException();
        }

        $invitations = 0;
        foreach ($joueurs->findBy(['categorie' => $categorie, 'ancien' => false]) as $j) {
            foreach ($this->etat($j)['a_inviter'] as $email => $qui) {
                $this->inviterUne($j, $email, $qui);
                ++$invitations;
            }
        }
        $this->addFlash($invitations ? 'succes' : 'info', $invitations
            ? \sprintf('%d invitation%s préparée%2$s.', $invitations, $invitations > 1 ? 's' : '')
            : 'Toutes les adresses de cette catégorie ont déjà un accès.');

        return $this->redirectToRoute('app_famille_index', ['categorie' => $categorie->getId()]);
    }

    /** Invite une adresse de la fiche, ou toutes celles qui n'ont pas encore de compte. */
    #[Route('/joueurs/{id}/famille/inviter', name: 'app_famille_inviter', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[IsGranted(JoueurVoter::MODIFIER, 'joueur')]
    public function inviter(Request $request, Joueur $joueur): Response
    {
        if ($this->isCsrfTokenValid('inviter-famille' . $joueur->getId(), $request->getPayload()->getString('_token'))) {
            $adresses = $this->acces->adresses($joueur);
            $email = $request->getPayload()->getString('email');
            $choisies = '' === $email ? $this->etat($joueur)['a_inviter'] : [$email => $adresses[$email] ?? 'parent1'];
            foreach ($choisies as $e => $qui) {
                $this->inviterUne($joueur, $e, $qui);
            }
        }

        return $this->retourFiche($joueur);
    }

    #[Route('/joueurs/{id}/famille/{compte}/retirer', name: 'app_famille_retirer', requirements: ['id' => '\d+', 'compte' => '\d+'], methods: ['POST'])]
    #[IsGranted(JoueurVoter::MODIFIER, 'joueur')]
    public function retirer(Request $request, Joueur $joueur, Utilisateur $compte): Response
    {
        if ($compte->isFamille() && $compte->suit($joueur)
            && $this->isCsrfTokenValid('retirer-famille' . $compte->getId(), $request->getPayload()->getString('_token'))) {
            $this->acces->retirer($joueur, $compte);
            $this->addFlash('succes', \sprintf('%s ne suit plus %s.', $compte->getEmail(), $joueur->getPrenom()));
        }

        return $this->retourFiche($joueur);
    }

    /** @return array{comptes: list<Utilisateur>, a_inviter: array<string, string>} */
    private function etat(Joueur $joueur): array
    {
        return $this->acces->etat($joueur);
    }

    private function inviterUne(Joueur $joueur, string $email, string $qui): void
    {
        try {
            $resultat = $this->acces->inviter($joueur, $email, $qui);
        } catch (\DomainException $e) {
            $this->addFlash('erreur', $e->getMessage());

            return;
        }
        if (null === $resultat['lien']) {
            $this->addFlash('succes', \sprintf('%s a déjà un compte : %s y est ajouté.', $email, $joueur->getPrenom()));
        } else {
            // Le lien n'est affiché qu'une fois (voir famille/_liens.html.twig) : il n'est pas conservé en clair.
            $this->addFlash('lien_famille', ['email' => $email, 'joueur' => $joueur->getNomComplet(), 'lien' => $resultat['lien'], 'envoye' => $resultat['envoye']]);
        }
    }

    /** @param list<Categorie> $proposees */
    private function categorie(int $id, array $proposees): ?Categorie
    {
        foreach ($proposees as $c) {
            if ($c->getId() === $id) {
                return $c;
            }
        }

        return null;
    }

    private function retourFiche(Joueur $joueur): Response
    {
        return $this->redirect($this->generateUrl('app_joueur_voir', ['id' => $joueur->getId()]) . '#famille');
    }
}
