<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261110141342 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Recrutement de l\'encadrement : candidatures (CV) d\'éducateurs, dirigeants, administratifs, bénévoles et du corps médical';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE candidature (id INT AUTO_INCREMENT NOT NULL, nom VARCHAR(50) NOT NULL, prenom VARCHAR(50) NOT NULL, poste_vise VARCHAR(20) NOT NULL, fonction VARCHAR(100) DEFAULT NULL, date_naissance DATE DEFAULT NULL, telephone VARCHAR(20) DEFAULT NULL, email VARCHAR(180) DEFAULT NULL, adresse VARCHAR(120) DEFAULT NULL, code_postal VARCHAR(10) DEFAULT NULL, ville VARCHAR(100) DEFAULT NULL, diplomes VARCHAR(255) DEFAULT NULL, experience LONGTEXT DEFAULT NULL, disponibilites VARCHAR(255) DEFAULT NULL, club_actuel VARCHAR(80) DEFAULT NULL, origine VARCHAR(100) DEFAULT NULL, date_reception DATE DEFAULT NULL, statut VARCHAR(20) NOT NULL, date_entretien DATETIME DEFAULT NULL, appreciation LONGTEXT DEFAULT NULL, cv VARCHAR(255) DEFAULT NULL, fiche VARCHAR(30) DEFAULT NULL, club_id INT NOT NULL, INDEX IDX_E33BD3B861190A32 (club_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE candidature ADD CONSTRAINT FK_E33BD3B861190A32 FOREIGN KEY (club_id) REFERENCES club (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE candidature DROP FOREIGN KEY FK_E33BD3B861190A32');
        $this->addSql('DROP TABLE candidature');
    }
}
