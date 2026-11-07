<?php

namespace App\Security;

use App\Entity\Categorie;
use App\Entity\Joueur;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Un utilisateur voit et modifie les joueurs des secteurs auxquels il a accès
 * (ROLE_FORMATION pour la Formation, etc.). Le droit Infirmerie n'élargit pas cette liste :
 * son périmètre propre est donné par secteursMedicaux().
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
        return $this->secteursModifiables();
    }

    /**
     * Périmètre médical (Infirmerie, dossiers médicaux, visite médicale) : null pour tout le club
     * (administrateur, médecin du club, compte médical seul), sinon les secteurs de l'éducateur.
     *
     * @return list<string>|null
     */
    public function secteursMedicaux(): ?array
    {
        foreach (['ROLE_ADMIN', 'ROLE_MEDECIN', 'ROLE_MEDICAL_SEUL'] as $role) {
            if ($this->security->isGranted($role)) {
                return null;
            }
        }

        return $this->secteursVisibles();
    }

    /** Ce joueur est-il dans le périmètre médical du compte connecté ? */
    public function dansPerimetreMedical(Joueur $joueur): bool
    {
        $secteurs = $this->secteursMedicaux();

        return null === $secteurs || \in_array($joueur->getCategorie()?->getSecteur(), $secteurs, true);
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
