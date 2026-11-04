<?php

namespace App\Service;

use App\Entity\Categorie;
use App\Entity\Club;
use App\Entity\Utilisateur;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Crée un club qui démarre à zéro : le club, ses catégories proposées et son premier
 * compte, administrateur avec tous les droits.
 */
class CreationClub
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly UserPasswordHasherInterface $hasher,
    ) {
    }

    public function creer(Club $club, Utilisateur $administrateur, string $motDePasse): void
    {
        $this->em->persist($club);

        $ordre = 0;
        foreach (Categorie::MODELES as $secteur => $noms) {
            foreach ($noms as $nom) {
                $this->em->persist((new Categorie())->setNom($nom)->setSecteur($secteur)->setOrdre(++$ordre)->setClub($club)
                    ->setDureeMatch(Categorie::dureeParDefaut($nom)));
            }
        }

        $administrateur
            ->setClub($club)
            ->setRolesAttribues(array_keys(Utilisateur::ROLES))
            ->setPassword($this->hasher->hashPassword($administrateur, $motDePasse));
        $this->em->persist($administrateur);

        $this->em->flush();
    }
}
