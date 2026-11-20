<?php

namespace App\Service;

use App\Entity\Categorie;
use App\Entity\Educateur;
use App\Entity\Rencontre;
use App\Entity\Utilisateur;
use App\Repository\CategorieRepository;
use App\Repository\RencontreRepository;

/**
 * Équipes dont s'occupe un compte de l'encadrement, pour « Mon compte » : éducateur de l'équipe (fiche Encadrement),
 * responsable de ses matchs, personne désignée, responsable du secteur ; avec le prochain match de chacune.
 */
class MesEquipes
{
    public function __construct(
        private readonly CategorieRepository $categories,
        private readonly RencontreRepository $rencontres,
        private readonly ResponsablesMatchs $responsables,
        private readonly FicheDuCompte $fiches,
    ) {
    }

    /**
     * @return list<array{categorie: Categorie, roles: list<string>, prochain: ?Rencontre}>
     */
    public function de(Utilisateur $u): array
    {
        $educateur = [];
        foreach ($this->fiches->fiches($u) as $f) {
            if ($f['fiche'] instanceof Educateur) {
                foreach ($f['fiche']->getEquipes() as $c) {
                    $educateur[$c->getId()] = true;
                }
            }
        }
        $liste = [];
        foreach ($this->categories->findBy([], ['ordre' => 'ASC', 'nom' => 'ASC']) as $c) {
            $roles = array_values(array_filter([
                isset($educateur[$c->getId()]) ? 'Éducateur' : null,
                $this->responsables->estResponsable($c, $u) ? 'Responsable des matchs' : null,
                $c->getRedacteursMatchs()->contains($u) ? 'Désigné pour les matchs' : null,
                $this->responsables->estResponsableSecteur($c, $u) ? 'Responsable secteur ' . $c->getSecteurLibelle() : null,
            ]));
            if ($roles) {
                $liste[] = ['categorie' => $c, 'roles' => $roles, 'prochain' => $this->prochainMatch($c)];
            }
        }

        return $liste;
    }

    private function prochainMatch(Categorie $c): ?Rencontre
    {
        return $this->rencontres->createQueryBuilder('r')
            ->andWhere('r.categorie = :c')->setParameter('c', $c)
            ->andWhere('r.date >= :jour')->setParameter('jour', new \DateTimeImmutable('today'))
            ->orderBy('r.date', 'ASC')->addOrderBy('r.heure', 'ASC')
            ->setMaxResults(1)
            ->getQuery()->getOneOrNullResult();
    }
}
