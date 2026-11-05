<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Fiche joueur : père et mère deviennent parent 1 et parent 2. Simple renommage, les noms saisis sont conservés.
 */
final class Version20261105201738 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Joueurs : nom et profession du père / de la mère renommés parent 1 / parent 2';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE joueur CHANGE nom_pere nom_parent1 VARCHAR(50) DEFAULT NULL, CHANGE profession_pere profession_parent1 VARCHAR(255) DEFAULT NULL, CHANGE nom_mere nom_parent2 VARCHAR(50) DEFAULT NULL, CHANGE profession_mere profession_parent2 VARCHAR(255) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE joueur CHANGE nom_parent1 nom_pere VARCHAR(50) DEFAULT NULL, CHANGE profession_parent1 profession_pere VARCHAR(255) DEFAULT NULL, CHANGE nom_parent2 nom_mere VARCHAR(50) DEFAULT NULL, CHANGE profession_parent2 profession_mere VARCHAR(255) DEFAULT NULL');
    }
}
