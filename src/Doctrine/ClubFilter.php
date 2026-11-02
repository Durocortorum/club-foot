<?php

namespace App\Doctrine;

use App\Entity\AppartientAuClub;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Query\Filter\SQLFilter;

/**
 * Ajoute « club_id = club de l'utilisateur » à chaque table de données de club, dans toutes
 * les requêtes (listes, recherches, jointures, sous-requêtes, chargement d'une fiche par son id).
 * Activé par ClubCourant dès que l'utilisateur est connu.
 */
class ClubFilter extends SQLFilter
{
    public function addFilterConstraint(ClassMetadata $targetEntity, string $targetTableAlias): string
    {
        if (!$targetEntity->getReflectionClass()->implementsInterface(AppartientAuClub::class)) {
            return '';
        }

        return \sprintf('%s.club_id = %s', $targetTableAlias, $this->getParameter('club'));
    }
}
