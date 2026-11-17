<?php

namespace App\Twig;

use App\Service\MatchDirect;
use App\Service\SynchroFeuille;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * {{ reussite(actions, 'passe') }} : pourcentage de réussite (passes, duels, contrôles, frappes) des actions notées en direct ;
 * {{ direct_a_reporter(rencontre) }} : buts, cartons et changements notés en direct qui manquent sur la feuille de match.
 */
class MatchDirectExtension extends AbstractExtension
{
    public function __construct(private readonly SynchroFeuille $synchro)
    {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('reussite', [MatchDirect::class, 'reussite']),
            new TwigFunction('direct_a_reporter', $this->synchro->aReporter(...)),
        ];
    }
}
