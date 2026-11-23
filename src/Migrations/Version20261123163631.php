<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261123163631 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Objectifs individuels de progression des joueurs (pourcentage et historique) et observations de l\'entraîneur';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE objectif_joueur (id INT AUTO_INCREMENT NOT NULL, saison VARCHAR(9) NOT NULL, titre VARCHAR(120) NOT NULL, theme VARCHAR(20) NOT NULL, description LONGTEXT DEFAULT NULL, progression INT NOT NULL, historique JSON NOT NULL, echeance DATE DEFAULT NULL, cree_le DATETIME NOT NULL, cree_par_nom VARCHAR(170) DEFAULT NULL, joueur_id INT NOT NULL, club_id INT NOT NULL, INDEX IDX_6175B35DC0D0D586 (saison), INDEX IDX_6175B35DA9E2D76C (joueur_id), INDEX IDX_6175B35D61190A32 (club_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE observation_joueur (id INT AUTO_INCREMENT NOT NULL, date DATE NOT NULL, texte LONGTEXT NOT NULL, lien_video VARCHAR(255) DEFAULT NULL, partage TINYINT(1) DEFAULT 1 NOT NULL, auteur_nom VARCHAR(170) DEFAULT NULL, joueur_id INT NOT NULL, objectif_id INT DEFAULT NULL, club_id INT NOT NULL, INDEX IDX_481A0FE6A9E2D76C (joueur_id), INDEX IDX_481A0FE6157D1AD4 (objectif_id), INDEX IDX_481A0FE661190A32 (club_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE objectif_joueur ADD CONSTRAINT FK_6175B35DA9E2D76C FOREIGN KEY (joueur_id) REFERENCES joueur (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE objectif_joueur ADD CONSTRAINT FK_6175B35D61190A32 FOREIGN KEY (club_id) REFERENCES club (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE observation_joueur ADD CONSTRAINT FK_481A0FE6A9E2D76C FOREIGN KEY (joueur_id) REFERENCES joueur (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE observation_joueur ADD CONSTRAINT FK_481A0FE6157D1AD4 FOREIGN KEY (objectif_id) REFERENCES objectif_joueur (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE observation_joueur ADD CONSTRAINT FK_481A0FE661190A32 FOREIGN KEY (club_id) REFERENCES club (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE objectif_joueur DROP FOREIGN KEY FK_6175B35DA9E2D76C');
        $this->addSql('ALTER TABLE objectif_joueur DROP FOREIGN KEY FK_6175B35D61190A32');
        $this->addSql('ALTER TABLE observation_joueur DROP FOREIGN KEY FK_481A0FE6A9E2D76C');
        $this->addSql('ALTER TABLE observation_joueur DROP FOREIGN KEY FK_481A0FE6157D1AD4');
        $this->addSql('ALTER TABLE observation_joueur DROP FOREIGN KEY FK_481A0FE661190A32');
        $this->addSql('DROP TABLE objectif_joueur');
        $this->addSql('DROP TABLE observation_joueur');
    }
}
