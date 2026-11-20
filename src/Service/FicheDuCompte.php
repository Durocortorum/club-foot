<?php

namespace App\Service;

use App\Entity\Intervenant;
use App\Entity\Utilisateur;
use App\Repository\ResponsableSecteurRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Fiches Encadrement (éducateur, dirigeant, administratif, bénévole, arbitre) d'un compte de connexion :
 * même adresse e-mail, sinon même prénom et nom. Sert à « Mon compte » (ma fiche, mes équipes).
 */
class FicheDuCompte
{
    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    /**
     * Fiches en activité de ce compte, par type (« educateurs »…).
     *
     * @return list<array{type: string, libelle: string, fiche: Intervenant}>
     */
    public function fiches(Utilisateur $u): array
    {
        $email = mb_strtolower(trim((string) $u->getEmail()));
        $nom = ResponsableSecteurRepository::nom($u->getPrenom(), $u->getNom());
        $parEmail = $parNom = [];
        foreach (Encadrement::TYPES as $type => $t) {
            foreach ($this->em->getRepository($t['classe'])->findBy(['ancien' => false]) as $i) {
                $ligne = ['type' => $type, 'libelle' => rtrim($t['libelle'], 's'), 'fiche' => $i];
                if ($email && mb_strtolower(trim((string) $i->getEmail())) === $email) {
                    $parEmail[] = $ligne;
                } elseif (ResponsableSecteurRepository::nom($i->getPrenom(), $i->getNom()) === $nom) {
                    $parNom[] = $ligne;
                }
            }
        }

        // L'adresse e-mail prime : le prénom et le nom ne servent que si aucune fiche n'a la même adresse.
        return $parEmail ?: $parNom;
    }

    /** Fiche d'un type donné de ce compte (pour la modifier), ou null. */
    public function fiche(Utilisateur $u, string $type): ?Intervenant
    {
        foreach ($this->fiches($u) as $f) {
            if ($f['type'] === $type) {
                return $f['fiche'];
            }
        }

        return null;
    }
}
