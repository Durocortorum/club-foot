<?php

namespace App\Twig;

use App\Service\MatchDirect;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/** {{ reussite(actions, 'passe') }} : pourcentage de réussite (passes, duels, contrôles, frappes) des actions notées en direct. */
class MatchDirectExtension extends AbstractExtension
{
    public function getFunctions(): array
    {
        return [new TwigFunction('reussite', [MatchDirect::class, 'reussite'])];
    }
}
