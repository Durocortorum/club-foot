<?php

namespace App\Doctrine;

use App\Entity\AppartientAuClub;
use App\Service\ClubCourant;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\PrePersistEventArgs;
use Doctrine\ORM\Event\PreUpdateEventArgs;
use Doctrine\ORM\Events;

/**
 * Rattache chaque nouvelle donnée au club de l'utilisateur, et refuse d'écrire
 * une donnée d'un autre club (garde-fou en plus du filtre de lecture ClubFilter).
 */
#[AsDoctrineListener(event: Events::prePersist)]
#[AsDoctrineListener(event: Events::preUpdate)]
class ClubListener
{
    public function __construct(private readonly ClubCourant $clubCourant)
    {
    }

    public function prePersist(PrePersistEventArgs $args): void
    {
        $this->verifier($args->getObject());
    }

    public function preUpdate(PreUpdateEventArgs $args): void
    {
        $this->verifier($args->getObject());
    }

    private function verifier(object $entite): void
    {
        if (!$entite instanceof AppartientAuClub) {
            return;
        }
        $club = $this->clubCourant->club();
        if (null === $entite->getClub()) {
            $entite->setClub($club ?? throw new \LogicException(\sprintf('Aucun club pour enregistrer %s.', $entite::class)));
        } elseif ($club && $entite->getClub()->getId() !== $club->getId()) {
            throw new \LogicException(\sprintf('Écriture refusée : %s appartient à un autre club.', $entite::class));
        }
    }
}
