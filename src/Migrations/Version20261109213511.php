<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261109213511 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Arbitres (niveau, équipement), sponsors et leurs participations par saison';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE arbitre (id INT AUTO_INCREMENT NOT NULL, nom VARCHAR(50) NOT NULL, prenom VARCHAR(50) NOT NULL, date_naissance DATE DEFAULT NULL, adresse VARCHAR(120) DEFAULT NULL, code_postal VARCHAR(10) DEFAULT NULL, ville VARCHAR(100) DEFAULT NULL, tel_fixe VARCHAR(20) DEFAULT NULL, tel_portable1 VARCHAR(20) DEFAULT NULL, tel_portable2 VARCHAR(20) DEFAULT NULL, tel_professionnel VARCHAR(20) DEFAULT NULL, email VARCHAR(180) DEFAULT NULL, licence VARCHAR(20) DEFAULT NULL, fonction VARCHAR(255) DEFAULT NULL, fonction2 VARCHAR(255) DEFAULT NULL, diplome VARCHAR(255) DEFAULT NULL, memo LONGTEXT DEFAULT NULL, photo VARCHAR(255) DEFAULT NULL, ancien TINYINT(1) NOT NULL, niveau VARCHAR(40) DEFAULT NULL, equipement LONGTEXT DEFAULT NULL, club_id INT NOT NULL, INDEX IDX_20B2E66E61190A32 (club_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE partenariat_sponsor (id INT AUTO_INCREMENT NOT NULL, saison VARCHAR(9) NOT NULL, nature VARCHAR(40) NOT NULL, montant NUMERIC(10, 2) DEFAULT NULL, description VARCHAR(255) DEFAULT NULL, contrat_debut DATE DEFAULT NULL, contrat_fin DATE DEFAULT NULL, sponsor_id INT NOT NULL, club_id INT NOT NULL, INDEX IDX_147E285912F7FB51 (sponsor_id), INDEX IDX_147E285961190A32 (club_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE sponsor (id INT AUTO_INCREMENT NOT NULL, nom VARCHAR(120) NOT NULL, type VARCHAR(20) NOT NULL, contact VARCHAR(120) DEFAULT NULL, adresse VARCHAR(160) DEFAULT NULL, code_postal VARCHAR(10) DEFAULT NULL, ville VARCHAR(100) DEFAULT NULL, telephone VARCHAR(30) DEFAULT NULL, email VARCHAR(180) DEFAULT NULL, site_web VARCHAR(255) DEFAULT NULL, memo LONGTEXT DEFAULT NULL, ancien TINYINT(1) NOT NULL, club_id INT NOT NULL, INDEX IDX_818CC9D461190A32 (club_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE arbitre ADD CONSTRAINT FK_20B2E66E61190A32 FOREIGN KEY (club_id) REFERENCES club (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE partenariat_sponsor ADD CONSTRAINT FK_147E285912F7FB51 FOREIGN KEY (sponsor_id) REFERENCES sponsor (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE partenariat_sponsor ADD CONSTRAINT FK_147E285961190A32 FOREIGN KEY (club_id) REFERENCES club (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE sponsor ADD CONSTRAINT FK_818CC9D461190A32 FOREIGN KEY (club_id) REFERENCES club (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE arbitre DROP FOREIGN KEY FK_20B2E66E61190A32');
        $this->addSql('ALTER TABLE partenariat_sponsor DROP FOREIGN KEY FK_147E285912F7FB51');
        $this->addSql('ALTER TABLE partenariat_sponsor DROP FOREIGN KEY FK_147E285961190A32');
        $this->addSql('ALTER TABLE sponsor DROP FOREIGN KEY FK_818CC9D461190A32');
        $this->addSql('DROP TABLE arbitre');
        $this->addSql('DROP TABLE partenariat_sponsor');
        $this->addSql('DROP TABLE sponsor');
    }
}
