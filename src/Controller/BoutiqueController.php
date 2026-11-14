<?php

namespace App\Controller;

use App\Entity\ArticleBoutique;
use App\Entity\CommandeBoutique;
use App\Entity\Cotisation;
use App\Entity\Rencontre;
use App\Form\ArticleBoutiqueType;
use App\Repository\ArticleBoutiqueRepository;
use App\Repository\CommandeBoutiqueRepository;
use App\Service\Boutique;
use App\Service\Photos;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Boutique côté club (droit « Finances ») : commandes des familles à régler, préparer et remettre ;
 * articles en vente, prix, tailles, stock et photo.
 */
#[Route('/boutique')]
#[IsGranted('ROLE_FINANCES_LECTURE')]
class BoutiqueController extends AbstractController
{
    /** Filtres de la liste des commandes : clé => [libellé, statuts]. */
    private const FILTRES = [
        'en_cours' => ['En cours', [CommandeBoutique::A_REGLER, CommandeBoutique::PAYEE, CommandeBoutique::PRETE]],
        'a_regler' => ['À régler', [CommandeBoutique::A_REGLER]],
        'a_preparer' => ['À préparer', [CommandeBoutique::PAYEE]],
        'pretes' => ['Prêtes à retirer', [CommandeBoutique::PRETE]],
        'remises' => ['Remises', [CommandeBoutique::REMISE]],
        'annulees' => ['Annulées', [CommandeBoutique::ANNULEE]],
    ];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly Boutique $boutique,
    ) {
    }

    #[Route('', name: 'app_boutique', methods: ['GET'])]
    public function commandes(Request $request, CommandeBoutiqueRepository $commandes): Response
    {
        $filtre = \array_key_exists($f = $request->query->getString('filtre', 'en_cours'), self::FILTRES) ? $f : 'en_cours';
        $toutes = $commandes->findBy([], ['creeLe' => 'DESC']);
        [$debut, $fin] = Rencontre::bornesSaison(Rencontre::saisonDe(new \DateTimeImmutable('today')));
        $compte = [];
        foreach (self::FILTRES as $cle => [, $statuts]) {
            $compte[$cle] = \count(array_filter($toutes, fn (CommandeBoutique $c) => \in_array($c->getStatut(), $statuts, true)));
        }

        return $this->render('boutique/commandes.html.twig', [
            'commandes' => array_values(array_filter($toutes, fn (CommandeBoutique $c) => \in_array($c->getStatut(), self::FILTRES[$filtre][1], true))),
            'filtres' => self::FILTRES,
            'filtre' => $filtre,
            'compte' => $compte,
            'ventes_saison' => array_sum(array_map(fn (CommandeBoutique $c) => $c->isPayee() && $c->getPayeeLe() >= $debut && $c->getPayeeLe() <= $fin->setTime(23, 59, 59) ? $c->getTotalEuros() : 0, $toutes)),
            'modes' => array_values(array_diff(Cotisation::MODES, [Cotisation::MODE_EN_LIGNE])),
        ]);
    }

    /** Étapes d'une commande : réglée au club (avec le mode), prête (la famille est prévenue), remise, ou annulée. */
    #[Route('/commande/{id}/{action}', name: 'app_boutique_commande', requirements: ['id' => '\d+', 'action' => 'payee|prete|remise|annuler'], methods: ['POST'])]
    #[IsGranted('ROLE_FINANCES')]
    public function etape(Request $request, CommandeBoutique $commande, string $action): Response
    {
        if (!$this->isCsrfTokenValid('commande' . $commande->getId(), $request->getPayload()->getString('_token'))) {
            throw $this->createAccessDeniedException();
        }
        $message = match ($action) {
            'payee' => $commande->isARegler() ? (function () use ($commande, $request) {
                $mode = \in_array($m = $request->getPayload()->getString('mode'), Cotisation::MODES, true) ? $m : 'Autre';
                $commande->marquerPayee($mode);

                return \sprintf('Commande n° %s réglée (%s).', $commande->getNumero(), mb_strtolower($mode));
            })() : null,
            'prete' => CommandeBoutique::PAYEE === $commande->getStatut() ? (function () use ($commande) {
                $commande->marquerPrete();
                $this->em->flush();
                $this->boutique->annoncerPrete($commande);

                return \sprintf('Commande n° %s prête : la famille est prévenue.', $commande->getNumero());
            })() : null,
            'remise' => $commande->isPayee() && CommandeBoutique::REMISE !== $commande->getStatut() ? ($commande->marquerRemise() ? \sprintf('Commande n° %s remise.', $commande->getNumero()) : null) : null,
            'annuler' => $this->boutique->annuler($commande) ? \sprintf('Commande n° %s annulée, articles remis en stock.%s', $commande->getNumero(), $commande->getPayeeLe() ? ' Pensez à rembourser la famille.' : '') : null,
        };
        $this->em->flush();
        if ($message) {
            $this->addFlash('succes', $message);
        }

        return $this->redirect($this->generateUrl('app_boutique', ['filtre' => $request->getPayload()->getString('filtre') ?: 'en_cours']) . '#commande-' . $commande->getId());
    }

    #[Route('/articles', name: 'app_boutique_articles', methods: ['GET'])]
    public function articles(ArticleBoutiqueRepository $articles): Response
    {
        return $this->render('boutique/articles.html.twig', ['articles' => $articles->findBy([], ['enVente' => 'DESC', 'ordre' => 'ASC', 'nom' => 'ASC'])]);
    }

    #[Route('/articles/nouveau', name: 'app_boutique_article_nouveau', methods: ['GET', 'POST'])]
    #[Route('/articles/{id}/modifier', name: 'app_boutique_article_modifier', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    #[IsGranted('ROLE_FINANCES')]
    public function article(Request $request, Photos $photos, ?ArticleBoutique $article = null): Response
    {
        $article ??= new ArticleBoutique();
        $form = $this->createForm(ArticleBoutiqueType::class, $article);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            if ($fichier = $form->get('photo')->getData()) {
                $article->setPhoto($photos->enregistrer($fichier, 'article', $article->getPhoto()));
            }
            // Stock par taille : saisi sous le formulaire (une case par taille), seulement si le suivi est coché.
            $saisi = $request->request->all()['stock'] ?? null;
            $suivi = (bool) $form->get('suiviStock')->getData();
            $article->setStock($suivi ? array_map('intval', (array) $saisi) : null);
            $this->em->persist($article);
            $this->em->flush();
            if ($suivi && null === $saisi) {
                // Suivi du stock coché sans cases encore affichées (nouvel article) : on les montre maintenant.
                $this->addFlash('info', \sprintf('Article « %s » enregistré. Indiquez maintenant le stock de chaque taille.', $article->getNom()));

                return $this->redirectToRoute('app_boutique_article_modifier', ['id' => $article->getId(), '_fragment' => 'stock']);
            }
            $this->addFlash('succes', \sprintf('Article « %s » enregistré.', $article->getNom()));

            return $this->redirectToRoute('app_boutique_articles');
        }

        return $this->render('boutique/article.html.twig', ['form' => $form, 'article' => $article]);
    }

    #[Route('/articles/{id}/supprimer', name: 'app_boutique_article_supprimer', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[IsGranted('ROLE_FINANCES')]
    public function supprimer(Request $request, ArticleBoutique $article, Photos $photos): Response
    {
        if ($this->isCsrfTokenValid('supprimer-article' . $article->getId(), $request->getPayload()->getString('_token'))) {
            // Les commandes gardent le nom et le prix de l'article (lignes), même supprimé.
            $photos->supprimer($article->getPhoto());
            $this->em->remove($article);
            $this->em->flush();
            $this->addFlash('succes', 'Article supprimé.');
        }

        return $this->redirectToRoute('app_boutique_articles');
    }
}
