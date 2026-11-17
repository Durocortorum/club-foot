<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261117110000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Match en direct : titulaires choisis sur la tablette (joueurs sur le terrain au coup d\'envoi)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE rencontre ADD titulaires_direct JSON DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE rencontre DROP titulaires_direct');
    }
}
