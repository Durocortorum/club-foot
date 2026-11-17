<?php

namespace App\Twig;

use App\Entity\ActionMatch;
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

    /** @return array{buts: int, cartons: int, changements: int} buts (si la feuille n'en a aucun), cartons de joueurs encore sans carton sur la feuille, changements absents des remplacements */
    public function aReporter(Rencontre $rencontre): array
    {
        if (!$rencontre->getId()) {
            return ['buts' => 0, 'cartons' => 0, 'changements' => 0];
        }
        $avecCarton = [];
        foreach ($rencontre->getParticipations() as $p) {
            if ($p->getCarton() && $p->getJoueur()) {
                $avecCarton[$p->getJoueur()->getId()] = true;
            }
        }
        $remplacements = [];
        foreach ($rencontre->getRemplacements() as $r) {
            $remplacements[] = [$r->getSortant()?->getId(), $r->getEntrant()?->getId(), $r->getMinute()];
        }
        $buts = $cartons = $changements = 0;
        foreach ($this->actions->duMatch($rencontre) as $a) {
            if (\in_array($a->getType(), ['B', 'BA'], true)) {
                ++$buts;
            } elseif (ActionMatch::CHANGEMENT === $a->getType()) {
                $changements += $a->getJoueur() && !\in_array([$a->getJoueur()->getId(), $a->getDetails()['entrant'] ?? 0, $a->getMinute()], $remplacements, true) ? 1 : 0;
            } elseif (\in_array($a->getType(), ['CJ', 'CR'], true) && $a->getJoueur() && !isset($avecCarton[$a->getJoueur()->getId()])) {
                ++$cartons;
            }
        }

        return ['buts' => \count($rencontre->getButs()) ? 0 : $buts, 'cartons' => $cartons, 'changements' => $changements];
    }
}
