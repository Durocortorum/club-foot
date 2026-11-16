<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261116084211 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Acceptation des conditions (CGV du club, CGU des comptes famille) : date et version';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE club ADD conditions_acceptees_le DATETIME DEFAULT NULL, ADD conditions_version VARCHAR(20) DEFAULT NULL');
        $this->addSql('ALTER TABLE utilisateur ADD conditions_acceptees_le DATETIME DEFAULT NULL, ADD conditions_version VARCHAR(20) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE club DROP conditions_acceptees_le, DROP conditions_version');
        $this->addSql('ALTER TABLE utilisateur DROP conditions_acceptees_le, DROP conditions_version');
    }
}
