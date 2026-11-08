<?php

namespace App\Service;

use App\Entity\Joueur;
use App\Entity\Proche;
use App\Entity\Utilisateur;
use App\Repository\ProcheRepository;
use App\Security\JoueurVoter;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * Personnes à contacter d'un joueur majeur. Le joueur les gère dans son espace, l'encadrement qui peut
 * modifier sa fiche aussi. Elles remplacent, pour un majeur, les champs « parent 1 / parent 2 » de la fiche :
 * à la première consultation, ces parents sont repris comme personnes à contacter.
 * Une personne avec un e-mail peut recevoir l'accès à l'espace du joueur (compte famille), sur décision du joueur.
 */
class ProchesJoueur
{
    public function __construct(
        private readonly ProcheRepository $proches,
        private readonly AccesFamille $acces,
        private readonly EntityManagerInterface $em,
        private readonly Security $security,
    ) {
    }

    /** @return list<Proche> personnes à contacter du joueur majeur (parents de la fiche repris la première fois) */
    public function liste(Joueur $joueur): array
    {
        $liste = $this->proches->duJoueur($joueur);
        if (!$liste && !$joueur->isMineur() && $this->reprendreParents($joueur)) {
            $liste = $this->proches->duJoueur($joueur);
        }

        return $liste;
    }

    /**
     * Personnes à contacter, avec le compte famille qui suit déjà le joueur à cette adresse (ou null).
     *
     * @return list<array{proche: Proche, compte: ?Utilisateur}>
     */
    public function avecAcces(Joueur $joueur): array
    {
        $comptes = [];
        foreach ($this->acces->comptes($joueur) as $c) {
            $comptes[$c->getEmail()] = $c;
        }

        return array_map(fn (Proche $p) => ['proche' => $p, 'compte' => $comptes[$p->getEmail()] ?? null], $this->liste($joueur));
    }

    /** Le compte connecté gère-t-il les personnes à contacter de ce joueur ? (le joueur majeur, ou son encadrement) */
    public function peutGerer(Joueur $joueur): bool
    {
        if ($joueur->isMineur()) {
            return false;
        }
        $moi = $this->security->getUser();

        return $this->security->isGranted(JoueurVoter::MODIFIER, $joueur)
            || ($moi instanceof Utilisateur && $this->acces->decideAccesParents($moi, $joueur));
    }

    /** Seul le joueur lui-même ouvre ou retire l'accès à son espace. */
    public function peutDonnerAcces(Joueur $joueur): bool
    {
        $moi = $this->security->getUser();

        return $moi instanceof Utilisateur && $this->acces->decideAccesParents($moi, $joueur);
    }

    /**
     * Ce compte famille voit-il les statistiques du joueur ? Joueur mineur : oui (lui et ses parents).
     * Joueur majeur : lui-même, et les personnes à contacter qu'il a autorisées.
     */
    public function voitStatistiques(Utilisateur $compte, Joueur $joueur): bool
    {
        if (!$compte->suit($joueur)) {
            return false;
        }
        if ($joueur->isMineur() || $this->acces->estLeJoueur($compte, $joueur)) {
            return true;
        }
        foreach ($this->proches->duJoueur($joueur) as $p) {
            if ($p->getEmail() === $compte->getEmail() && $p->isVoitStatistiques()) {
                return true;
            }
        }

        return false;
    }

    /** Compte famille qui suit le joueur avec l'adresse de cette personne, s'il y en a un. */
    public function compte(Proche $proche): ?Utilisateur
    {
        foreach ($this->acces->comptes($proche->getJoueur()) as $c) {
            if ($c->getEmail() === $proche->getEmail()) {
                return $c;
            }
        }

        return null;
    }

    /** Supprime la personne ; si elle avait accès à l'espace du joueur, cet accès est retiré aussi. */
    public function supprimer(Proche $proche): void
    {
        if ($compte = $this->compte($proche)) {
            $this->acces->retirer($proche->getJoueur(), $compte);
        }
        $this->em->remove($proche);
        $this->em->flush();
    }

    /**
     * Joueur majeur : les parents saisis sur sa fiche deviennent des personnes à contacter, et leurs
     * nom, téléphone et e-mail quittent la fiche (ils ne sont plus en double, ni cachés).
     */
    private function reprendreParents(Joueur $joueur): bool
    {
        $repris = false;
        foreach ([1, 2] as $n) {
            $nom = $joueur->{'getNomParent' . $n}();
            $tel = $joueur->{'getTelPortable' . $n}();
            $email = $joueur->{'getEmailParent' . $n}();
            if (!$nom && !$tel && !$email) {
                continue;
            }
            $this->em->persist((new Proche($joueur))
                ->setNom($nom ?: 'Parent ' . $n)
                ->setLien('Parent')
                ->setTelephone($tel)
                ->setEmail($email));
            $joueur->{'setNomParent' . $n}(null)->{'setTelPortable' . $n}(null)->{'setEmailParent' . $n}(null);
            $repris = true;
        }
        if ($repris) {
            $this->em->flush();
        }

        return $repris;
    }
}
