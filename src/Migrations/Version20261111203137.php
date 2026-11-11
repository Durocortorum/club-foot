<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261111203137 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Transport : vehicules du club et deplacements (frais kilometriques)';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE deplacement (id INT AUTO_INCREMENT NOT NULL, date DATE NOT NULL, objet VARCHAR(150) NOT NULL, moyen VARCHAR(40) NOT NULL, conducteur VARCHAR(120) DEFAULT NULL, passagers SMALLINT DEFAULT NULL, km INT DEFAULT NULL, taux_km NUMERIC(6, 3) DEFAULT NULL, frais NUMERIC(10, 2) DEFAULT NULL, statut VARCHAR(15) DEFAULT NULL, regle_le DATE DEFAULT NULL, note VARCHAR(255) DEFAULT NULL, categorie_id INT DEFAULT NULL, vehicule_id INT DEFAULT NULL, club_id INT NOT NULL, INDEX IDX_1296FAC2BCF5E72D (categorie_id), INDEX IDX_1296FAC24A4A3511 (vehicule_id), INDEX IDX_1296FAC261190A32 (club_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE vehicule (id INT AUTO_INCREMENT NOT NULL, nom VARCHAR(80) NOT NULL, immatriculation VARCHAR(20) DEFAULT NULL, places SMALLINT DEFAULT NULL, kilometrage INT DEFAULT NULL, assurance DATE DEFAULT NULL, controle_technique DATE DEFAULT NULL, revision DATE DEFAULT NULL, carte_carburant VARCHAR(60) DEFAULT NULL, badge_autoroute VARCHAR(60) DEFAULT NULL, note LONGTEXT DEFAULT NULL, club_id INT NOT NULL, INDEX IDX_292FFF1D61190A32 (club_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE deplacement ADD CONSTRAINT FK_1296FAC2BCF5E72D FOREIGN KEY (categorie_id) REFERENCES categorie (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE deplacement ADD CONSTRAINT FK_1296FAC24A4A3511 FOREIGN KEY (vehicule_id) REFERENCES vehicule (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE deplacement ADD CONSTRAINT FK_1296FAC261190A32 FOREIGN KEY (club_id) REFERENCES club (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE vehicule ADD CONSTRAINT FK_292FFF1D61190A32 FOREIGN KEY (club_id) REFERENCES club (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE deplacement DROP FOREIGN KEY FK_1296FAC2BCF5E72D');
        $this->addSql('ALTER TABLE deplacement DROP FOREIGN KEY FK_1296FAC24A4A3511');
        $this->addSql('ALTER TABLE deplacement DROP FOREIGN KEY FK_1296FAC261190A32');
        $this->addSql('ALTER TABLE vehicule DROP FOREIGN KEY FK_292FFF1D61190A32');
        $this->addSql('DROP TABLE deplacement');
        $this->addSql('DROP TABLE vehicule');
    }
}
