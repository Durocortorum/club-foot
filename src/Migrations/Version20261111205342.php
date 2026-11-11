<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261111205342 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Autoevaluation des joueurs (RPE)';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE ressenti (id INT AUTO_INCREMENT NOT NULL, date DATE NOT NULL, rpe SMALLINT NOT NULL, duree SMALLINT NOT NULL, commentaire VARCHAR(255) DEFAULT NULL, saisi_le DATETIME NOT NULL, joueur_id INT NOT NULL, entrainement_id INT DEFAULT NULL, rencontre_id INT DEFAULT NULL, club_id INT NOT NULL, UNIQUE INDEX ressenti_joueur_entrainement (joueur_id, entrainement_id), UNIQUE INDEX ressenti_joueur_rencontre (joueur_id, rencontre_id), INDEX IDX_50914BA3A9E2D76C (joueur_id), INDEX IDX_50914BA3A15E8FD (entrainement_id), INDEX IDX_50914BA36CFC0818 (rencontre_id), INDEX IDX_50914BA361190A32 (club_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE ressenti ADD CONSTRAINT FK_50914BA3A9E2D76C FOREIGN KEY (joueur_id) REFERENCES joueur (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE ressenti ADD CONSTRAINT FK_50914BA3A15E8FD FOREIGN KEY (entrainement_id) REFERENCES entrainement (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE ressenti ADD CONSTRAINT FK_50914BA36CFC0818 FOREIGN KEY (rencontre_id) REFERENCES rencontre (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE ressenti ADD CONSTRAINT FK_50914BA361190A32 FOREIGN KEY (club_id) REFERENCES club (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE ressenti DROP FOREIGN KEY FK_50914BA3A9E2D76C');
        $this->addSql('ALTER TABLE ressenti DROP FOREIGN KEY FK_50914BA3A15E8FD');
        $this->addSql('ALTER TABLE ressenti DROP FOREIGN KEY FK_50914BA36CFC0818');
        $this->addSql('ALTER TABLE ressenti DROP FOREIGN KEY FK_50914BA361190A32');
        $this->addSql('DROP TABLE ressenti');
    }
}
