<?php

namespace App\Twig;

use App\Entity\Rencontre;
use App\Repository\ActionMatchRepository;
use App\Service\MatchDirect;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * {{ reussite(actions, 'passe') }} : pourcentage de réussite (passes, duels, contrôles, frappes) des actions notées en direct ;
 * {{ direct_a_reporter(rencontre) }} : buts et cartons notés en direct pas encore sur la feuille de match.
 */
class MatchDirectExtension extends AbstractExtension
{
    public function __construct(private readonly ActionMatchRepository $actions)
    {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('reussite', [MatchDirect::class, 'reussite']),
            new TwigFunction('direct_a_reporter', $this->aReporter(...)),
        ];
    }

    /** @return array{buts: int, cartons: int} buts (si la feuille n'en a aucun) et cartons de joueurs encore sans carton sur la feuille */
    public function aReporter(Rencontre $rencontre): array
    {
        if (!$rencontre->getId()) {
            return ['buts' => 0, 'cartons' => 0];
        }
        $avecCarton = [];
        foreach ($rencontre->getParticipations() as $p) {
            if ($p->getCarton() && $p->getJoueur()) {
                $avecCarton[$p->getJoueur()->getId()] = true;
            }
        }
        $buts = $cartons = 0;
        foreach ($this->actions->duMatch($rencontre) as $a) {
            if (\in_array($a->getType(), ['B', 'BA'], true)) {
                ++$buts;
            } elseif (\in_array($a->getType(), ['CJ', 'CR'], true) && $a->getJoueur() && !isset($avecCarton[$a->getJoueur()->getId()])) {
                ++$cartons;
            }
        }

        return ['buts' => \count($rencontre->getButs()) ? 0 : $buts, 'cartons' => $cartons];
    }
}
