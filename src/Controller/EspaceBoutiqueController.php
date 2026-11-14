<?php

namespace App\Controller;

use App\Entity\ArticleBoutique;
use App\Entity\CommandeBoutique;
use App\Entity\PaiementEnLigne;
use App\Entity\Utilisateur;
use App\Repository\ArticleBoutiqueRepository;
use App\Repository\CommandeBoutiqueRepository;
use App\Service\Boutique;
use App\Service\ErreurHelloAsso;
use App\Service\PaiementsEnLigne;
use App\Service\Photos;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Boutique du club dans l'espace famille : articles en vente, panier, commande (réglée en ligne si le club a
 * l'option paiement en ligne, sinon au club), suivi de ses commandes. Retrait au club.
 */
#[Route('/espace/boutique')]
class EspaceBoutiqueController extends AbstractController
{
    public function __construct(
        private readonly Boutique $boutique,
        private readonly PaiementsEnLigne $paiements,
    ) {
    }

    #[Route('', name: 'app_espace_boutique', methods: ['GET'])]
    public function index(ArticleBoutiqueRepository $articles, CommandeBoutiqueRepository $commandes): Response
    {
        $moi = $this->famille();
        $panier = $this->boutique->panier();

        return $this->render('espace/boutique.html.twig', [
            'articles' => $articles->findBy(['enVente' => true], ['ordre' => 'ASC', 'nom' => 'ASC']),
            'panier' => $panier,
            'total' => array_sum(array_column($panier, 'montant')),
            'commandes' => $commandes->findBy(['compte' => $moi], ['creeLe' => 'DESC'], 20),
            'joueurs' => $moi->getJoueurs(),
            'en_ligne' => null !== $this->paiements->connexion($moi->getClub()),
            'quantite_max' => Boutique::QUANTITE_MAX,
        ]);
    }

    #[Route('/ajouter/{id}', name: 'app_espace_boutique_ajouter', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function ajouter(Request $request, ArticleBoutique $article): Response
    {
        $this->famille();
        $this->verifierJeton($request, 'boutique');
        try {
            $this->boutique->ajouter($article, $request->getPayload()->getString('taille', ArticleBoutique::TAILLE_UNIQUE), $request->getPayload()->getInt('quantite', 1));
            $this->addFlash('succes', \sprintf('« %s » ajouté au panier.', $article->getNom()));
        } catch (\InvalidArgumentException $e) {
            $this->addFlash('erreur', $e->getMessage());
        }

        return $this->redirectToRoute('app_espace_boutique', ['_fragment' => 'panier']);
    }

    #[Route('/retirer', name: 'app_espace_boutique_retirer', methods: ['POST'])]
    public function retirer(Request $request): Response
    {
        $this->famille();
        $this->verifierJeton($request, 'boutique');
        $this->boutique->retirer($request->getPayload()->getString('cle'));

        return $this->redirectToRoute('app_espace_boutique', ['_fragment' => 'panier']);
    }

    /** Passe la commande ; « en_ligne » : directement vers la page de paiement HelloAsso. */
    #[Route('/commander', name: 'app_espace_boutique_commander', methods: ['POST'])]
    public function commander(Request $request): Response
    {
        $moi = $this->famille();
        $this->verifierJeton($request, 'boutique');
        $joueur = null;
        foreach ($moi->getJoueurs() as $j) {
            if ($j->getId() === $request->getPayload()->getInt('joueur') || 1 === $moi->getJoueurs()->count()) {
                $joueur = $j;
            }
        }
        if (!$joueur) {
            $this->addFlash('erreur', 'Choisissez pour qui est la commande.');

            return $this->redirectToRoute('app_espace_boutique', ['_fragment' => 'panier']);
        }
        try {
            $commande = $this->boutique->commander($moi, $joueur, $request->getPayload()->getString('note') ?: null);
        } catch (\InvalidArgumentException $e) {
            $this->addFlash('erreur', $e->getMessage());

            return $this->redirectToRoute('app_espace_boutique', ['_fragment' => 'panier']);
        }
        if ('en_ligne' === $request->getPayload()->getString('reglement')) {
            return $this->payerEnLigne($commande, $moi);
        }
        $this->addFlash('succes', \sprintf('Commande n° %s enregistrée : à régler au club. Vous serez prévenu quand elle sera prête.', $commande->getNumero()));

        return $this->redirectToRoute('app_espace_boutique', ['_fragment' => 'commandes']);
    }

    /** Règle en ligne une commande encore « à régler » (paiement abandonné, ou commande passée pour un règlement au club). */
    #[Route('/commande/{id}/payer', name: 'app_espace_boutique_payer', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function payer(Request $request, CommandeBoutique $commande): Response
    {
        $moi = $this->famille();
        $this->verifierJeton($request, 'boutique');
        if ($commande->getCompte() !== $moi) {
            throw $this->createAccessDeniedException();
        }

        return $this->payerEnLigne($commande, $moi);
    }

    #[Route('/commande/{id}/annuler', name: 'app_espace_boutique_annuler', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function annuler(Request $request, CommandeBoutique $commande): Response
    {
        $moi = $this->famille();
        $this->verifierJeton($request, 'boutique');
        // La famille n'annule qu'une commande pas encore réglée ; ensuite, c'est le club.
        if ($commande->getCompte() !== $moi || !$commande->isARegler()) {
            throw $this->createAccessDeniedException();
        }
        $this->boutique->annuler($commande);
        $this->addFlash('succes', \sprintf('Commande n° %s annulée.', $commande->getNumero()));

        return $this->redirectToRoute('app_espace_boutique', ['_fragment' => 'commandes']);
    }

    /** Photo d'un article (les photos des fiches, elles, ne sont servies qu'à l'encadrement). */
    #[Route('/photo/{nom}', name: 'app_boutique_photo', requirements: ['nom' => 'article-[a-z0-9]+'], methods: ['GET'])]
    public function photo(string $nom, Photos $photos, Request $request): Response
    {
        $reponse = new BinaryFileResponse($photos->chemin($nom . '.jpg') ?? throw $this->createNotFoundException());
        $reponse->setPrivate();
        $reponse->setMaxAge(86400);
        $reponse->isNotModified($request);

        return $reponse;
    }

    private function payerEnLigne(CommandeBoutique $commande, Utilisateur $moi): Response
    {
        try {
            $r = $this->paiements->payerCommande($commande, $moi, fn (PaiementEnLigne $p) => [
                'retour' => $this->generateUrl('app_espace_paiement_retour', ['id' => $p->getId()], UrlGeneratorInterface::ABSOLUTE_URL),
                'erreur' => $this->generateUrl('app_espace_paiement_retour', ['id' => $p->getId(), 'erreur' => 1], UrlGeneratorInterface::ABSOLUTE_URL),
                'abandon' => $this->generateUrl('app_espace_paiement_retour', ['id' => $p->getId(), 'abandon' => 1], UrlGeneratorInterface::ABSOLUTE_URL),
            ]);
        } catch (ErreurHelloAsso $e) {
            $this->addFlash('erreur', \sprintf('Commande n° %s enregistrée, mais le paiement en ligne est impossible pour le moment : %s Vous pourrez la régler plus tard, ici ou au club.', $commande->getNumero(), $e->getMessage()));

            return $this->redirectToRoute('app_espace_boutique', ['_fragment' => 'commandes']);
        }

        return $this->redirect($r['redirection']);
    }

    private function verifierJeton(Request $request, string $id): void
    {
        if (!$this->isCsrfTokenValid($id, $request->getPayload()->getString('_token'))) {
            throw $this->createAccessDeniedException();
        }
    }

    private function famille(): Utilisateur
    {
        /** @var Utilisateur $moi */
        $moi = $this->getUser();
        if (!$moi->isFamille()) {
            throw $this->createAccessDeniedException('La boutique de l\'espace famille est réservée aux familles.');
        }

        return $moi;
    }
}
