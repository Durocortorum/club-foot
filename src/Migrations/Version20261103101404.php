<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261103101404 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Buts adverses : numéro de maillot du buteur';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE but ADD numero_adverse SMALLINT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE but DROP numero_adverse');
    }
}
