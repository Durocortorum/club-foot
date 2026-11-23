<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261123204504 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'État de forme du jour des joueurs : fatigue, sommeil, douleurs, motivation (un par joueur et par jour)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE etat_forme (id INT AUTO_INCREMENT NOT NULL, jour DATE NOT NULL, fatigue SMALLINT NOT NULL, sommeil SMALLINT NOT NULL, douleurs SMALLINT NOT NULL, motivation SMALLINT NOT NULL, commentaire VARCHAR(200) DEFAULT NULL, saisi_par VARCHAR(120) DEFAULT NULL, saisi_le DATETIME NOT NULL, joueur_id INT NOT NULL, club_id INT NOT NULL, UNIQUE INDEX UNIQ_4F7C821FA9E2D76CDA17D9C5 (joueur_id, jour), INDEX IDX_4F7C821FA9E2D76C (joueur_id), INDEX IDX_4F7C821F61190A32 (club_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE etat_forme ADD CONSTRAINT FK_4F7C821FA9E2D76C FOREIGN KEY (joueur_id) REFERENCES joueur (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE etat_forme ADD CONSTRAINT FK_4F7C821F61190A32 FOREIGN KEY (club_id) REFERENCES club (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE etat_forme DROP FOREIGN KEY FK_4F7C821FA9E2D76C');
        $this->addSql('ALTER TABLE etat_forme DROP FOREIGN KEY FK_4F7C821F61190A32');
        $this->addSql('DROP TABLE etat_forme');
    }
}
