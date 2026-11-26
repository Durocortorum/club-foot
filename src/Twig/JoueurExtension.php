<?php

namespace App\Twig;

use App\Security\JoueurVoter;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/** Droits sur les joueurs utiles aux gabarits communs (onglets de la rubrique Joueurs). */
class JoueurExtension extends AbstractExtension
{
    public function __construct(private readonly JoueurVoter $voter)
    {
    }

    public function getFunctions(): array
    {
        // Peut gérer des joueurs dans au moins un secteur : création, accès des familles.
        return [new TwigFunction('gere_des_joueurs', fn () => [] !== $this->voter->secteursModifiables())];
    }
}
