<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261120215041 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Utilisateur : suspension du compte (depuis, jusqu\'au, motif)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE utilisateur ADD suspendu_le DATETIME DEFAULT NULL, ADD suspendu_jusqu_au DATE DEFAULT NULL, ADD motif_suspension VARCHAR(255) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE utilisateur DROP suspendu_le, DROP suspendu_jusqu_au, DROP motif_suspension');
    }
}
