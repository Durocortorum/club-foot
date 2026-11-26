<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261126200000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Analyse de l\'adversaire : effectif (numéros de maillot, noms connus, postes)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE fiche_adversaire ADD effectif JSON DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE fiche_adversaire DROP effectif');
    }
}
