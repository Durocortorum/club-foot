<?php

namespace App\Security;

use App\Entity\Categorie;
use App\Entity\CreneauEntrainement;
use App\Entity\Entrainement;
use App\Entity\Rencontre;
use App\Entity\Seance;
use App\Entity\SemainePresence;
use App\Entity\Utilisateur;
use App\Service\ResponsablesMatchs;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Matchs, présences et séances d'entraînement : un éducateur gère ceux des secteurs auxquels il a accès.
 * Le droit « Rapports de match » donne accès aux matchs de tous les secteurs.
 *
 * @extends Voter<string, Rencontre|Categorie|SemainePresence|Seance|Entrainement|CreneauEntrainement>
 */
class ActiviteVoter extends Voter
{
    public const GERER = 'ACTIVITE_GERER';
    /**
     * Modifier le rapport d'un match (feuille, score, buts, direct, composition, suppression) : si l'équipe a un
     * éducateur responsable des matchs, lui seul et les personnes qu'il a désignées (plus l'administrateur) ;
     * sinon, comme GERER. Les autres voient le match sans le modifier.
     */
    public const MODIFIER_MATCH = 'MATCH_MODIFIER';

    public function __construct(
        private readonly Security $security,
        private readonly JoueurVoter $joueurs,
        private readonly ResponsablesMatchs $responsables,
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
        if (self::MODIFIER_MATCH === $attribute) {
            return $subject instanceof Rencontre || $subject instanceof Categorie;
        }

        return self::GERER === $attribute && ($subject instanceof Rencontre || $subject instanceof SemainePresence || $subject instanceof Seance
            || $subject instanceof Entrainement || $subject instanceof CreneauEntrainement);
    }

    /** Ce compte peut-il modifier les rapports de match de cette équipe ? */
    public function peutTenirLesMatchs(?Categorie $categorie, mixed $utilisateur): bool
    {
        if ($this->security->isGranted('ROLE_ADMIN')) {
            return true;
        }
        if (null === $categorie) {
            return false;
        }
        if (!$this->security->isGranted('ROLE_RAPPORT_MATCH') && !\in_array($categorie->getSecteur(), $this->joueurs->secteursModifiables(), true)) {
            return false;
        }

        return $this->responsables->peutTenir($categorie, $utilisateur instanceof Utilisateur ? $utilisateur : null);
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token): bool
    {
        if (self::MODIFIER_MATCH === $attribute) {
            $categorie = $subject instanceof Categorie ? $subject : $subject->getCategorie();

            return $this->peutTenirLesMatchs($categorie, $token->getUser());
        }
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
