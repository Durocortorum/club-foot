<?php

namespace App\Twig;

use App\Entity\CommandeBoutique;
use App\Repository\ArticleBoutiqueRepository;
use App\Repository\CommandeBoutiqueRepository;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * {{ boutique_ouverte() }} : au moins un article en vente (menu Boutique des familles) ;
 * {{ boutique_a_traiter() }} : commandes réglées à préparer ou à régler (pastille du menu de l'encadrement).
 */
class BoutiqueExtension extends AbstractExtension
{
    public function __construct(
        private readonly ArticleBoutiqueRepository $articles,
        private readonly CommandeBoutiqueRepository $commandes,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('boutique_ouverte', fn () => null !== $this->articles->findOneBy(['enVente' => true])),
            new TwigFunction('boutique_a_traiter', fn () => $this->commandes->count(['statut' => [CommandeBoutique::A_REGLER, CommandeBoutique::PAYEE]])),
        ];
    }
}
