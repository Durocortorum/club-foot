<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261116102145 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Match en direct à plusieurs tablettes : auteur de chaque action, chrono commun du match';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE action_match ADD auteur_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE action_match ADD CONSTRAINT FK_9D9509B960BB6FE6 FOREIGN KEY (auteur_id) REFERENCES utilisateur (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IDX_9D9509B960BB6FE6 ON action_match (auteur_id)');
        $this->addSql('ALTER TABLE rencontre ADD chrono_direct JSON DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE action_match DROP FOREIGN KEY FK_9D9509B960BB6FE6');
        $this->addSql('DROP INDEX IDX_9D9509B960BB6FE6 ON action_match');
        $this->addSql('ALTER TABLE action_match DROP auteur_id');
        $this->addSql('ALTER TABLE rencontre DROP chrono_direct');
    }
}
