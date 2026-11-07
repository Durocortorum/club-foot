<?php

namespace App\Security;

use App\Entity\Categorie;
use App\Entity\Joueur;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Un utilisateur voit et modifie les joueurs des secteurs auxquels il a accès
 * (ROLE_FORMATION pour la Formation, etc.). L'infirmerie voit tous les joueurs.
 *
 * @extends Voter<string, Joueur>
 */
class JoueurVoter extends Voter
{
    public const VOIR = 'JOUEUR_VOIR';
    public const MODIFIER = 'JOUEUR_MODIFIER';

    public function __construct(private readonly Security $security)
    {
    }

    /**
     * Secteurs dont l'utilisateur connecté peut consulter les joueurs.
     *
     * @return list<string>
     */
    public function secteursVisibles(): array
    {
        $tous = $this->security->isGranted('ROLE_INFIRMERIE_LECTURE');

        return array_keys(array_filter(
            Categorie::SECTEURS,
            fn (array $s) => $tous || $this->security->isGranted($s['role'])
        ));
    }

    /**
     * Secteurs dans lesquels l'utilisateur connecté peut créer ou modifier des joueurs.
     *
     * @return list<string>
     */
    public function secteursModifiables(): array
    {
        return array_keys(array_filter(
            Categorie::SECTEURS,
            fn (array $s) => $this->security->isGranted($s['role'])
        ));
    }

    protected function supports(string $attribute, mixed $subject): bool
    {
        return \in_array($attribute, [self::VOIR, self::MODIFIER], true) && $subject instanceof Joueur;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token): bool
    {
        $secteur = $subject->getCategorie()?->getSecteur();
        if (null === $secteur) {
            return $this->security->isGranted('ROLE_ADMIN');
        }

        $secteurs = self::VOIR === $attribute ? $this->secteursVisibles() : $this->secteursModifiables();

        return \in_array($secteur, $secteurs, true);
    }
}
