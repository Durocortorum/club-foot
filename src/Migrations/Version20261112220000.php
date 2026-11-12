<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Droit « Discipline » séparé de la Scolarité (incidents et amendes de tous les joueurs, majeurs compris).
 * Comptes existants : Scolarité (modifier) ou éducateur d'un secteur → Discipline (modifier) ;
 * Scolarité (voir) seulement → Discipline (voir). Les accès restent donc au moins ceux d'avant.
 */
final class Version20261112220000 extends AbstractMigration
{
    private const MODIFIER = ['ROLE_SCOLAIRE', 'ROLE_ECOLE_FOOT', 'ROLE_PREFORMATION', 'ROLE_FORMATION', 'ROLE_ELITE', 'ROLE_PRO'];

    public function getDescription(): string
    {
        return 'Droit Discipline separe de la Scolarite, donne aux comptes existants';
    }

    public function up(Schema $schema): void
    {
        foreach ($this->connection->fetchAllAssociative('SELECT id, roles FROM utilisateur WHERE famille = 0') as $u) {
            $roles = json_decode($u['roles'], true) ?: [];
            $ajout = match (true) {
                (bool) array_intersect(self::MODIFIER, $roles) => 'ROLE_DISCIPLINE',
                \in_array('ROLE_SCOLAIRE_LECTURE', $roles, true) => 'ROLE_DISCIPLINE_LECTURE',
                default => null,
            };
            if ($ajout && !\in_array($ajout, $roles, true)) {
                $this->addSql('UPDATE utilisateur SET roles = ? WHERE id = ?', [json_encode([...$roles, $ajout]), $u['id']]);
            }
        }
    }

    public function down(Schema $schema): void
    {
        foreach ($this->connection->fetchAllAssociative('SELECT id, roles FROM utilisateur WHERE famille = 0') as $u) {
            $roles = array_values(array_diff(json_decode($u['roles'], true) ?: [], ['ROLE_DISCIPLINE', 'ROLE_DISCIPLINE_LECTURE']));
            $this->addSql('UPDATE utilisateur SET roles = ? WHERE id = ?', [json_encode($roles), $u['id']]);
        }
    }
}
