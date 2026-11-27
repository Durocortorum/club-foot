<?php

namespace App\Twig;

use App\Entity\Convoque;
use App\Entity\Utilisateur;
use App\Repository\ConvoqueRepository;
use Symfony\Bundle\SecurityBundle\Security;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Bouton « Convocations » de la barre du bas (famille et joueur) : convocations à venir sans réponse,
 * pour la pastille, et le joueur vers lequel mener (le premier qui doit répondre).
 */
class ConvocationsExtension extends AbstractExtension
{
    /** @var list<Convoque>|null */
    private ?array $enAttente = null;

    public function __construct(
        private readonly ConvoqueRepository $convoques,
        private readonly Security $security,
    ) {
    }

    public function getFunctions(): array
    {
        return [new TwigFunction('convocations_a_repondre', $this->aRepondre(...))];
    }

    /** @return list<Convoque> convocations à venir du compte famille, sans réponse, la plus proche en tête */
    public function aRepondre(): array
    {
        if (null === $this->enAttente) {
            $u = $this->security->getUser();
            $this->enAttente = $u instanceof Utilisateur && $u->isFamille()
                ? array_values(array_filter($this->convoques->aVenir($u->getJoueurs()), fn (Convoque $c) => null === $c->getReponse()))
                : [];
        }

        return $this->enAttente;
    }
}
