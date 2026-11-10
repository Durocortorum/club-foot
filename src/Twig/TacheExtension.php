<?php

namespace App\Twig;

use App\Entity\Utilisateur;
use App\Repository\TacheRepository;
use Symfony\Bundle\SecurityBundle\Security;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Pastille du menu « Tâches » : tâches non faites du club, et combien sont confiées à l'utilisateur connecté.
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
            new TwigFunction('taches_ouvertes', function (): array {
                $u = $this->security->getUser();
                if (!$u instanceof Utilisateur || $u->isFamille()) {
                    return ['total' => 0, 'miennes' => 0];
                }

                return ['total' => $this->taches->compterOuvertes(), 'miennes' => $this->taches->compterOuvertesDe($u)];
            }),
        ];
    }
}
