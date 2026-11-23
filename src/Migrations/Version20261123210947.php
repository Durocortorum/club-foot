<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261123210947 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Bibliothèque de coups de pied arrêtés : combinaisons (corner, coup franc, touche) avec schéma animé et rôles des joueurs';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE combinaison_cpa (id INT AUTO_INCREMENT NOT NULL, titre VARCHAR(100) NOT NULL, type VARCHAR(12) NOT NULL, cote VARCHAR(10) NOT NULL, consignes LONGTEXT DEFAULT NULL, dessin JSON NOT NULL, roles JSON NOT NULL, modifie_le DATETIME DEFAULT NULL, modifie_par_nom VARCHAR(170) DEFAULT NULL, club_id INT NOT NULL, INDEX IDX_7D3111BC61190A32 (club_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE combinaison_cpa ADD CONSTRAINT FK_7D3111BC61190A32 FOREIGN KEY (club_id) REFERENCES club (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE combinaison_cpa DROP FOREIGN KEY FK_7D3111BC61190A32');
        $this->addSql('DROP TABLE combinaison_cpa');
    }
}
