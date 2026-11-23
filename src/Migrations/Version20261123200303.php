<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261123200303 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Présences : note (sur 10) et forme de la semaine d\'entraînement de chaque joueur';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE presence ADD note_semaine DOUBLE PRECISION DEFAULT NULL, ADD forme VARCHAR(10) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE presence DROP note_semaine, DROP forme');
    }
}
