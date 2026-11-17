<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261117120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Feuille de match : tracé des buts sur le terrain (départ du ballon, entrée dans le but)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE but ADD trace JSON DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE but DROP trace');
    }
}
