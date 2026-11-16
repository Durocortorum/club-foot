<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261116095358 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Match en direct : actions notées pendant le match (joueur, action, zone, minute)';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE action_match (id INT AUTO_INCREMENT NOT NULL, uuid VARCHAR(36) NOT NULL, type VARCHAR(3) NOT NULL, periode SMALLINT NOT NULL, minute SMALLINT NOT NULL, zx SMALLINT NOT NULL, zy SMALLINT NOT NULL, notee DATETIME NOT NULL, rencontre_id INT NOT NULL, joueur_id INT DEFAULT NULL, club_id INT NOT NULL, INDEX IDX_9D9509B96CFC081893C32DF36EC2ABB5 (rencontre_id, periode, minute), UNIQUE INDEX UNIQ_9D9509B9D17F50A6 (uuid), INDEX IDX_9D9509B96CFC0818 (rencontre_id), INDEX IDX_9D9509B9A9E2D76C (joueur_id), INDEX IDX_9D9509B961190A32 (club_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE action_match ADD CONSTRAINT FK_9D9509B96CFC0818 FOREIGN KEY (rencontre_id) REFERENCES rencontre (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE action_match ADD CONSTRAINT FK_9D9509B9A9E2D76C FOREIGN KEY (joueur_id) REFERENCES joueur (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE action_match ADD CONSTRAINT FK_9D9509B961190A32 FOREIGN KEY (club_id) REFERENCES club (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE action_match DROP FOREIGN KEY FK_9D9509B96CFC0818');
        $this->addSql('ALTER TABLE action_match DROP FOREIGN KEY FK_9D9509B9A9E2D76C');
        $this->addSql('ALTER TABLE action_match DROP FOREIGN KEY FK_9D9509B961190A32');
        $this->addSql('DROP TABLE action_match');
    }
}
