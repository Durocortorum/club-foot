<?php

namespace App\Twig;

use App\Entity\Utilisateur;
use App\Repository\MessageRepository;
use App\Security\JoueurVoter;
use Symfony\Bundle\SecurityBundle\Security;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Pastille du menu « Messages » : messages et réponses non lus (famille), réponses des familles
 * non lues (encadrement).
 */
class MessagerieExtension extends AbstractExtension
{
    public function __construct(
        private readonly MessageRepository $messages,
        private readonly Security $security,
        private readonly JoueurVoter $joueurs,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('messages_non_lus', $this->nonLus(...)),
            new TwigFunction('messages_importants', fn () => ($u = $this->utilisateur()) && $u->isFamille() ? $this->messages->importantsNonLus($u) : []),
        ];
    }

    private function nonLus(): int
    {
        $u = $this->utilisateur();
        if (!$u) {
            return 0;
        }
        if ($u->isFamille()) {
            return $this->messages->nonLusFamille($u);
        }

        // Encadrement : réponses des familles (et des collègues) non lues, plus les messages reçus de collègues.
        return $this->messages->reponsesNonLues($u, $this->security->isGranted('ROLE_ADMIN') ? null : $this->joueurs->secteursModifiables())
            + $this->messages->nonLusRecus($u);
    }

    private function utilisateur(): ?Utilisateur
    {
        $u = $this->security->getUser();

        return $u instanceof Utilisateur ? $u : null;
    }
}
