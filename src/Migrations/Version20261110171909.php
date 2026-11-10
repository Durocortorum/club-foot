<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261110171909 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Stages : fiche (dates, lieu, équipes, raison, tarif, places) et inscriptions des joueurs';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE inscription_stage (id INT AUTO_INCREMENT NOT NULL, inscrit_le DATETIME NOT NULL, par_famille TINYINT(1) NOT NULL, paye TINYINT(1) NOT NULL, stage_id INT NOT NULL, joueur_id INT NOT NULL, UNIQUE INDEX UNIQ_9035C7242298D193A9E2D76C (stage_id, joueur_id), INDEX IDX_9035C7242298D193 (stage_id), INDEX IDX_9035C724A9E2D76C (joueur_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE stage (id INT AUTO_INCREMENT NOT NULL, titre VARCHAR(120) NOT NULL, raison VARCHAR(80) DEFAULT NULL, debut DATE NOT NULL, fin DATE DEFAULT NULL, horaires VARCHAR(80) DEFAULT NULL, lieu VARCHAR(150) DEFAULT NULL, tarif NUMERIC(8, 2) DEFAULT NULL, detail_tarif VARCHAR(150) DEFAULT NULL, places INT DEFAULT NULL, description LONGTEXT DEFAULT NULL, inscriptions_familles TINYINT(1) NOT NULL, date_limite DATE DEFAULT NULL, club_id INT NOT NULL, INDEX IDX_C27C936961190A32 (club_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE stage_categorie (stage_id INT NOT NULL, categorie_id INT NOT NULL, INDEX IDX_7A762C152298D193 (stage_id), INDEX IDX_7A762C15BCF5E72D (categorie_id), PRIMARY KEY(stage_id, categorie_id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE inscription_stage ADD CONSTRAINT FK_9035C7242298D193 FOREIGN KEY (stage_id) REFERENCES stage (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE inscription_stage ADD CONSTRAINT FK_9035C724A9E2D76C FOREIGN KEY (joueur_id) REFERENCES joueur (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE stage ADD CONSTRAINT FK_C27C936961190A32 FOREIGN KEY (club_id) REFERENCES club (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE stage_categorie ADD CONSTRAINT FK_7A762C152298D193 FOREIGN KEY (stage_id) REFERENCES stage (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE stage_categorie ADD CONSTRAINT FK_7A762C15BCF5E72D FOREIGN KEY (categorie_id) REFERENCES categorie (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE inscription_stage DROP FOREIGN KEY FK_9035C7242298D193');
        $this->addSql('ALTER TABLE inscription_stage DROP FOREIGN KEY FK_9035C724A9E2D76C');
        $this->addSql('ALTER TABLE stage DROP FOREIGN KEY FK_C27C936961190A32');
        $this->addSql('ALTER TABLE stage_categorie DROP FOREIGN KEY FK_7A762C152298D193');
        $this->addSql('ALTER TABLE stage_categorie DROP FOREIGN KEY FK_7A762C15BCF5E72D');
        $this->addSql('DROP TABLE inscription_stage');
        $this->addSql('DROP TABLE stage');
        $this->addSql('DROP TABLE stage_categorie');
    }
}
