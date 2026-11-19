<?php

namespace App\Service;

use App\Entity\ArticleBoutique;
use App\Entity\CommandeBoutique;
use App\Entity\Joueur;
use App\Entity\Utilisateur;
use App\Repository\ArticleBoutiqueRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Boutique du club : panier de la famille (gardé dans la session), passage de commande (stock retiré),
 * annulation (stock remis), et avis à la famille quand sa commande est prête à retirer.
 */
class Boutique
{
    private const SESSION = 'panier_boutique';
    public const QUANTITE_MAX = 10;

    public function __construct(
        private readonly RequestStack $requetes,
        private readonly ArticleBoutiqueRepository $articles,
        private readonly EntityManagerInterface $em,
        private readonly NotificationsTelephone $notifications,
        private readonly MailerInterface $mailer,
        private readonly CourrielClub $courriel,
        private readonly UrlGeneratorInterface $urls,
        private readonly AccesFamille $acces,
        private readonly LoggerInterface $logger,
    ) {
    }

    /** @return array<string, int> « idArticle|taille » => quantité */
    private function brut(): array
    {
        return $this->requetes->getSession()->get(self::SESSION, []);
    }

    /**
     * Lignes du panier, avec les articles encore en vente.
     *
     * @return list<array{cle: string, article: ArticleBoutique, taille: string, quantite: int, montant: float}>
     */
    public function panier(): array
    {
        $lignes = [];
        foreach ($this->brut() as $cle => $quantite) {
            [$id, $taille] = explode('|', $cle, 2) + [1 => ''];
            $article = $this->articles->find((int) $id);
            if ($article && $article->isEnVente() && \in_array($taille, $article->getTailles(), true)) {
                $lignes[] = ['cle' => $cle, 'article' => $article, 'taille' => $taille, 'quantite' => $quantite, 'montant' => $article->getPrix() * $quantite];
            }
        }

        return $lignes;
    }

    public function ajouter(ArticleBoutique $article, string $taille, int $quantite): void
    {
        if (!$article->isEnVente() || !\in_array($taille, $article->getTailles(), true)) {
            throw new \InvalidArgumentException('Article ou taille indisponible.');
        }
        $panier = $this->brut();
        $cle = $article->getId() . '|' . $taille;
        $voulu = min(self::QUANTITE_MAX, ($panier[$cle] ?? 0) + max(1, $quantite));
        $disponible = $article->disponible($taille);
        if (null !== $disponible && $voulu > $disponible) {
            throw new \InvalidArgumentException($disponible > 0 ? \sprintf('Il n\'en reste que %d en %s.', $disponible, $taille) : 'Cette taille est épuisée.');
        }
        $panier[$cle] = $voulu;
        $this->requetes->getSession()->set(self::SESSION, $panier);
    }

    public function retirer(string $cle): void
    {
        $panier = $this->brut();
        unset($panier[$cle]);
        $this->requetes->getSession()->set(self::SESSION, $panier);
    }

    public function vider(): void
    {
        $this->requetes->getSession()->remove(self::SESSION);
    }

    /**
     * Passe la commande du panier : stock vérifié puis retiré, panier vidé.
     *
     * @throws \InvalidArgumentException panier vide ou stock insuffisant (message lisible)
     */
    public function commander(Utilisateur $compte, Joueur $joueur, ?string $note): CommandeBoutique
    {
        $lignes = $this->panier();
        if (!$lignes) {
            throw new \InvalidArgumentException('Votre panier est vide.');
        }
        $commande = $this->creer($compte, self::nomClient($compte), $joueur, $lignes, $note);
        $this->vider();

        return $commande;
    }

    /**
     * Commande saisie par le club pour une famille (au guichet, par téléphone…) : rattachée au compte famille du joueur
     * s'il en a un (la famille la voit alors dans son espace et est prévenue quand elle est prête).
     *
     * @param list<array{article: ArticleBoutique, taille: string, quantite: int}> $lignes
     *
     * @throws \InvalidArgumentException aucune ligne, article ou taille indisponible, stock insuffisant (message lisible)
     */
    public function commanderPourJoueur(Joueur $joueur, array $lignes, ?string $note): CommandeBoutique
    {
        if (!$lignes) {
            throw new \InvalidArgumentException('Ajoutez au moins un article.');
        }
        foreach ($lignes as $l) {
            if (!$l['article']->isEnVente() || !\in_array($l['taille'], $l['article']->getTailles(), true)) {
                throw new \InvalidArgumentException(\sprintf('« %s » en %s n\'est plus en vente.', $l['article']->getNom(), $l['taille']));
            }
        }
        $compte = $this->compteFamille($joueur);

        return $this->creer($compte, $compte ? self::nomClient($compte) : 'Famille ' . $joueur->getNom(), $joueur, $lignes, $note);
    }

    /** Compte famille rattaché au joueur (le premier s'il y en a plusieurs), ou null. */
    public function compteFamille(Joueur $joueur): ?Utilisateur
    {
        return $this->em->createQueryBuilder()
            ->select('u')->from(Utilisateur::class, 'u')
            ->join('u.joueurs', 'j')
            ->where('j = :joueur')->andWhere('u.famille = true')
            ->setParameter('joueur', $joueur)
            ->orderBy('u.id')->setMaxResults(1)
            ->getQuery()->getOneOrNullResult();
    }

    private static function nomClient(Utilisateur $compte): string
    {
        return $compte->isFamille() && 'Famille' === $compte->getPrenom() ? 'Famille ' . $compte->getNom() : trim($compte->getPrenom() . ' ' . $compte->getNom());
    }

    /**
     * Crée la commande : stock vérifié (par article et taille, lignes identiques additionnées) puis retiré.
     *
     * @param list<array{article: ArticleBoutique, taille: string, quantite: int}> $lignes
     */
    private function creer(?Utilisateur $compte, string $client, Joueur $joueur, array $lignes, ?string $note): CommandeBoutique
    {
        $voulu = [];
        foreach ($lignes as $l) {
            $cle = spl_object_id($l['article']) . '|' . $l['taille'];
            $voulu[$cle] = ['article' => $l['article'], 'taille' => $l['taille'], 'quantite' => ($voulu[$cle]['quantite'] ?? 0) + $l['quantite']];
        }
        foreach ($voulu as $l) {
            $disponible = $l['article']->disponible($l['taille']);
            if (null !== $disponible && $l['quantite'] > $disponible) {
                throw new \InvalidArgumentException(\sprintf('« %s » en %s : il n\'en reste que %d.', $l['article']->getNom(), $l['taille'], $disponible));
            }
        }
        $commande = (new CommandeBoutique($compte, $client, $joueur))->setNote($note);
        foreach ($voulu as $l) {
            $commande->ajouterLigne($l['article'], $l['taille'], $l['quantite']);
            $l['article']->mouvementStock($l['taille'], $l['quantite']);
        }
        $this->em->persist($commande);
        $this->em->flush();

        return $commande;
    }

    /** Annule la commande et remet les articles en stock. */
    public function annuler(CommandeBoutique $commande): bool
    {
        if (!$commande->annuler()) {
            return false;
        }
        foreach ($commande->getLignes() as $l) {
            $l->getArticle()?->mouvementStock($l->getTaille(), -$l->getQuantite());
        }
        $this->em->flush();

        return true;
    }

    /** La commande est prête : la famille est prévenue (notification sur le téléphone et e-mail). */
    public function annoncerPrete(CommandeBoutique $commande): void
    {
        $compte = $commande->getCompte();
        if (!$compte) {
            return;
        }
        $club = $commande->getClub();
        $url = $this->urls->generate('app_espace_boutique', ['_fragment' => 'commandes'], UrlGeneratorInterface::ABSOLUTE_URL);
        $texte = \sprintf('Commande n° %s (%d article%s) : à retirer au club.', $commande->getNumero(), $commande->getNombreArticles(), $commande->getNombreArticles() > 1 ? 's' : '');
        $this->notifications->envoyer([$compte], 'Votre commande est prête', $texte, $url);
        if ($this->acces->emailActif() && $compte->getEmail()) {
            try {
                $this->mailer->send($this->courriel->message($club, (string) $compte->getEmail(), \sprintf('%s : votre commande est prête', $club->getNom()), [
                    'titre' => 'Votre commande est prête',
                    'paragraphes' => [$texte],
                    'bouton' => ['libelle' => 'Voir ma commande', 'url' => $url],
                ]));
            } catch (TransportExceptionInterface $e) {
                $this->logger->error('Avis de commande prête non envoyé à {email} : {erreur}', ['email' => $compte->getEmail(), 'erreur' => $e->getMessage()]);
            }
        }
    }
}
