<?php

namespace App\Security;

use App\Entity\Rencontre;
use App\Entity\Seance;
use App\Entity\SemainePresence;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Matchs, présences et séances d'entraînement : un éducateur gère ceux des secteurs auxquels il a accès.
 * Le droit « Rapports de match » donne accès aux matchs de tous les secteurs.
 *
 * @extends Voter<string, Rencontre|SemainePresence|Seance>
 */
class ActiviteVoter extends Voter
{
    public const GERER = 'ACTIVITE_GERER';

    public function __construct(
        private readonly Security $security,
        private readonly JoueurVoter $joueurs,
    ) {
    }

    /**
     * Secteurs dont l'utilisateur voit les matchs, ou null pour tous.
     *
     * @return list<string>|null
     */
    public function secteursMatchs(): ?array
    {
        return $this->security->isGranted('ROLE_RAPPORT_MATCH') ? null : $this->joueurs->secteursModifiables();
    }

    protected function supports(string $attribute, mixed $subject): bool
    {
        return self::GERER === $attribute && ($subject instanceof Rencontre || $subject instanceof SemainePresence || $subject instanceof Seance);
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token): bool
    {
        if ($subject instanceof Rencontre && $this->security->isGranted('ROLE_RAPPORT_MATCH')) {
            return true;
        }
        $secteur = $subject->getCategorie()?->getSecteur();
        if (null === $secteur) {
            return $this->security->isGranted('ROLE_ADMIN');
        }

        return \in_array($secteur, $this->joueurs->secteursModifiables(), true);
    }
}
