<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261108200953 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Blessure : arrêt envisagé (nombre de jours, semaines ou mois)';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE blessure ADD arret_prevu SMALLINT DEFAULT NULL, ADD unite_arret VARCHAR(10) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE blessure DROP arret_prevu, DROP unite_arret');
    }
}
