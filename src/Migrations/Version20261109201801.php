<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261109201801 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Fiches administratifs et bénévoles (même fiche que les éducateurs et dirigeants)';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE administratif (id INT AUTO_INCREMENT NOT NULL, nom VARCHAR(50) NOT NULL, prenom VARCHAR(50) NOT NULL, date_naissance DATE DEFAULT NULL, adresse VARCHAR(120) DEFAULT NULL, code_postal VARCHAR(10) DEFAULT NULL, ville VARCHAR(100) DEFAULT NULL, tel_fixe VARCHAR(20) DEFAULT NULL, tel_portable1 VARCHAR(20) DEFAULT NULL, tel_portable2 VARCHAR(20) DEFAULT NULL, tel_professionnel VARCHAR(20) DEFAULT NULL, email VARCHAR(180) DEFAULT NULL, licence VARCHAR(20) DEFAULT NULL, fonction VARCHAR(255) DEFAULT NULL, fonction2 VARCHAR(255) DEFAULT NULL, diplome VARCHAR(255) DEFAULT NULL, memo LONGTEXT DEFAULT NULL, photo VARCHAR(255) DEFAULT NULL, ancien TINYINT(1) NOT NULL, club_id INT NOT NULL, INDEX IDX_145F75AA61190A32 (club_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE benevole (id INT AUTO_INCREMENT NOT NULL, nom VARCHAR(50) NOT NULL, prenom VARCHAR(50) NOT NULL, date_naissance DATE DEFAULT NULL, adresse VARCHAR(120) DEFAULT NULL, code_postal VARCHAR(10) DEFAULT NULL, ville VARCHAR(100) DEFAULT NULL, tel_fixe VARCHAR(20) DEFAULT NULL, tel_portable1 VARCHAR(20) DEFAULT NULL, tel_portable2 VARCHAR(20) DEFAULT NULL, tel_professionnel VARCHAR(20) DEFAULT NULL, email VARCHAR(180) DEFAULT NULL, licence VARCHAR(20) DEFAULT NULL, fonction VARCHAR(255) DEFAULT NULL, fonction2 VARCHAR(255) DEFAULT NULL, diplome VARCHAR(255) DEFAULT NULL, memo LONGTEXT DEFAULT NULL, photo VARCHAR(255) DEFAULT NULL, ancien TINYINT(1) NOT NULL, club_id INT NOT NULL, INDEX IDX_B4014FDB61190A32 (club_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE administratif ADD CONSTRAINT FK_145F75AA61190A32 FOREIGN KEY (club_id) REFERENCES club (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE benevole ADD CONSTRAINT FK_B4014FDB61190A32 FOREIGN KEY (club_id) REFERENCES club (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE administratif DROP FOREIGN KEY FK_145F75AA61190A32');
        $this->addSql('ALTER TABLE benevole DROP FOREIGN KEY FK_B4014FDB61190A32');
        $this->addSql('DROP TABLE administratif');
        $this->addSql('DROP TABLE benevole');
    }
}
