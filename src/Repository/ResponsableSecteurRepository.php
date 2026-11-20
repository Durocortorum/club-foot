<?php

namespace App\Repository;

use App\Entity\Categorie;
use App\Entity\ResponsableSecteur;
use App\Entity\Utilisateur;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ResponsableSecteur>
 */
class ResponsableSecteurRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ResponsableSecteur::class);
    }

    /**
     * Secteurs dont chaque personne est responsable (« Préformation »…), pour signaler les responsables de secteur
     * sur les fiches Encadrement : clés « e:adresse » (même adresse que le compte) et « n:prénom nom ».
     *
     * @return array<string, list<string>>
     */
    public function libellesParPersonne(): array
    {
        $liste = [];
        foreach ($this->findAll() as $r) {
            $u = $r->getUtilisateur();
            $libelle = Categorie::SECTEURS[$r->getSecteur()]['libelle'] ?? $r->getSecteur();
            $liste['e:' . mb_strtolower((string) $u->getEmail())][] = $libelle;
            $liste['n:' . self::nom($u->getPrenom(), $u->getNom())][] = $libelle;
        }

        return $liste;
    }

    /** Prénom et nom ramenés à une forme comparable (minuscules, espaces simples). */
    public static function nom(?string $prenom, ?string $nom): string
    {
        return mb_strtolower(preg_replace('/\s+/u', ' ', trim($prenom . ' ' . $nom)) ?? '');
    }

    /** Secteurs dont la personne de cette fiche est responsable : par l'adresse e-mail, sinon par le prénom et le nom. */
    public static function secteursDe(array $libelles, ?string $email, ?string $prenom, ?string $nom): array
    {
        return $libelles['e:' . mb_strtolower(trim((string) $email))] ?? $libelles['n:' . self::nom($prenom, $nom)] ?? [];
    }

    /** @return array<string, Utilisateur> responsable de chaque secteur du club (clé : secteur) */
    public function parSecteur(): array
    {
        $liste = [];
        foreach ($this->findAll() as $r) {
            $liste[$r->getSecteur()] = $r->getUtilisateur();
        }

        return $liste;
    }
}
