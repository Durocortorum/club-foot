<?php

namespace App\Twig;

use App\Entity\Utilisateur;
use App\Repository\TacheRepository;
use Symfony\Bundle\SecurityBundle\Security;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Pastille du menu « Tâches » : tâches non faites confiées à l'utilisateur connecté.
 */
class TacheExtension extends AbstractExtension
{
    public function __construct(
        private readonly TacheRepository $taches,
        private readonly Security $security,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('taches_ouvertes', function (): int {
                $u = $this->security->getUser();

                return $u instanceof Utilisateur && !$u->isFamille() ? $this->taches->compterOuvertesDe($u) : 0;
            }),
        ];
    }
}
