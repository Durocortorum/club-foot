<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Demandes d'accès aux données médicales, traitées par un médecin du club ou l'administrateur.
 */
final class Version20261107170000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return "Demandes d'accès aux données médicales (traitées par un médecin du club ou l'administrateur)";
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE demande_acces_medical (id INT AUTO_INCREMENT NOT NULL, section VARCHAR(20) NOT NULL, niveau SMALLINT NOT NULL, motif VARCHAR(255) DEFAULT NULL, demandee_le DATETIME NOT NULL, decision VARCHAR(10) DEFAULT NULL, traitee_le DATETIME DEFAULT NULL, traitee_par VARCHAR(180) DEFAULT NULL, demandeur_id INT NOT NULL, club_id INT NOT NULL, INDEX IDX_623D534284ACBE48 (decision), INDEX IDX_623D534295A6EE59 (demandeur_id), INDEX IDX_623D534261190A32 (club_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE demande_acces_medical ADD CONSTRAINT FK_623D534295A6EE59 FOREIGN KEY (demandeur_id) REFERENCES utilisateur (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE demande_acces_medical ADD CONSTRAINT FK_623D534261190A32 FOREIGN KEY (club_id) REFERENCES club (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE demande_acces_medical DROP FOREIGN KEY FK_623D534295A6EE59');
        $this->addSql('ALTER TABLE demande_acces_medical DROP FOREIGN KEY FK_623D534261190A32');
        $this->addSql('DROP TABLE demande_acces_medical');
    }
}
