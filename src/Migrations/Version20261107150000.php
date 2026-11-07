<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Droits « voir / modifier » pour Infirmerie, Visite médicale, Scolarité et Tests physiques.
 * Aucune colonne ne change : on complète les rôles des comptes de l'encadrement pour qu'ils gardent
 * exactement les accès qu'ils avaient :
 *  - Infirmerie : donnait aussi la visite médicale (poids, taille) → + Visite médicale (modifier) ;
 *  - Tests physiques : visibles par tous, saisis par les éducateurs de secteur et l'infirmerie
 *    → + Tests physiques (modifier) pour eux, (voir) pour les autres.
 */
final class Version20261107150000 extends AbstractMigration
{
    private const SECTEURS_OU_INFIRMERIE = ['ROLE_ECOLE_FOOT', 'ROLE_PREFORMATION', 'ROLE_FORMATION', 'ROLE_ELITE', 'ROLE_PRO', 'ROLE_INFIRMERIE'];
    private const NOUVEAUX = ['ROLE_INFIRMERIE_LECTURE', 'ROLE_VISITE_MEDICALE', 'ROLE_VISITE_MEDICALE_LECTURE', 'ROLE_SCOLAIRE_LECTURE', 'ROLE_TESTS_PHYSIQUES', 'ROLE_TESTS_PHYSIQUES_LECTURE'];

    public function getDescription(): string
    {
        return 'Droits voir / modifier : visite médicale et tests physiques ajoutés aux comptes existants (accès inchangés)';
    }

    public function up(Schema $schema): void
    {
        foreach ($this->connection->fetchAllAssociative('SELECT id, roles FROM utilisateur WHERE famille = 0') as $u) {
            $roles = json_decode($u['roles'], true) ?: [];
            $ajouts = [];
            if (\in_array('ROLE_INFIRMERIE', $roles, true)) {
                $ajouts[] = 'ROLE_VISITE_MEDICALE';
            }
            $ajouts[] = array_intersect(self::SECTEURS_OU_INFIRMERIE, $roles) ? 'ROLE_TESTS_PHYSIQUES' : 'ROLE_TESTS_PHYSIQUES_LECTURE';
            $nouveaux = array_values(array_unique([...$roles, ...$ajouts]));
            $this->addSql('UPDATE utilisateur SET roles = ? WHERE id = ?', [json_encode($nouveaux), $u['id']]);
        }
    }

    public function down(Schema $schema): void
    {
        foreach ($this->connection->fetchAllAssociative('SELECT id, roles FROM utilisateur WHERE famille = 0') as $u) {
            $roles = array_values(array_diff(json_decode($u['roles'], true) ?: [], self::NOUVEAUX));
            $this->addSql('UPDATE utilisateur SET roles = ? WHERE id = ?', [json_encode($roles), $u['id']]);
        }
    }
}
