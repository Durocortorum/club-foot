<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261110173603 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Stage obligatoire et date du dernier envoi aux joueurs';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE stage ADD obligatoire TINYINT(1) DEFAULT 0 NOT NULL, ADD prevenu_le DATETIME DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE stage DROP obligatoire, DROP prevenu_le');
    }
}
