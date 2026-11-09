<?php

namespace App\Service;

use App\Entity\Administratif;
use App\Entity\Arbitre;
use App\Entity\Benevole;
use App\Entity\Dirigeant;
use App\Entity\Educateur;
use App\Entity\Intervenant;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Toutes les fiches de l'encadrement (dirigeants, administratifs, éducateurs, bénévoles, arbitres),
 * désignées par une clé « type:id » (le type est celui des adresses /encadrement/{type}).
 */
class Encadrement
{
    public const TYPES = [
        'dirigeants' => ['classe' => Dirigeant::class, 'libelle' => 'Dirigeants'],
        'administratifs' => ['classe' => Administratif::class, 'libelle' => 'Administratifs'],
        'educateurs' => ['classe' => Educateur::class, 'libelle' => 'Éducateurs'],
        'benevoles' => ['classe' => Benevole::class, 'libelle' => 'Bénévoles'],
        'arbitres' => ['classe' => Arbitre::class, 'libelle' => 'Arbitres'],
    ];

    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    /**
     * Personnes en activité, par type, pour une liste de choix : libellé du groupe => [« Prénom NOM » => « type:id »].
     *
     * @return array<string, array<string, string>>
     */
    public function choix(): array
    {
        $choix = [];
        foreach (self::TYPES as $type => $t) {
            foreach ($this->em->getRepository($t['classe'])->findBy(['ancien' => false], ['nom' => 'ASC', 'prenom' => 'ASC']) as $i) {
                $choix[$t['libelle']][$i->getPrenom() . ' ' . $i->getNom() . ($i->getFonction() ? ' (' . $i->getFonction() . ')' : '')] = $type . ':' . $i->getId();
            }
        }

        return $choix;
    }

    /**
     * Fiches désignées par leurs clés, en une requête par type.
     *
     * @param list<string> $cles « type:id »
     *
     * @return array<string, Intervenant>
     */
    public function trouver(array $cles): array
    {
        $ids = [];
        foreach ($cles as $cle) {
            if (preg_match('/^([a-z]+):(\d+)$/', (string) $cle, $m) && isset(self::TYPES[$m[1]])) {
                $ids[$m[1]][] = (int) $m[2];
            }
        }
        $fiches = [];
        foreach ($ids as $type => $liste) {
            foreach ($this->em->getRepository(self::TYPES[$type]['classe'])->findBy(['id' => $liste]) as $i) {
                $fiches[$type . ':' . $i->getId()] = $i;
            }
        }

        return $fiches;
    }
}
