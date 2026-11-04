<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261103103220 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Feuille de match : minute du carton (« 34 », « 90+2 »)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE participation ADD minute_carton VARCHAR(6) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE participation DROP minute_carton');
    }
}
