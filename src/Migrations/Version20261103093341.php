<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261103093341 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Catégories : durée d\'un match, proposée d\'après le nom (même règle que Categorie::dureeParDefaut)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE categorie ADD duree_match SMALLINT DEFAULT NULL');
        $this->addSql("UPDATE categorie SET duree_match = CASE
            WHEN nom REGEXP '^U ?[0-9]([^0-9]|$)' THEN NULL
            WHEN nom REGEXP '^U ?1[01]([^0-9]|$)' THEN 50
            WHEN nom REGEXP '^U ?1[23]([^0-9]|$)' THEN 60
            WHEN nom REGEXP '^U ?1[45]([^0-9]|$)' THEN 80
            ELSE 90 END");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE categorie DROP duree_match');
    }
}
