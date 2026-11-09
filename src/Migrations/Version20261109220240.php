<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261109220240 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Organigramme du club : postes en arbre et personne de chaque poste';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE poste_organigramme (id INT AUTO_INCREMENT NOT NULL, titre VARCHAR(80) NOT NULL, ordre INT NOT NULL, personne_type VARCHAR(20) DEFAULT NULL, personne_id INT DEFAULT NULL, nom_libre VARCHAR(120) DEFAULT NULL, parent_id INT DEFAULT NULL, club_id INT NOT NULL, INDEX IDX_A0076985727ACA70 (parent_id), INDEX IDX_A007698561190A32 (club_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE poste_organigramme ADD CONSTRAINT FK_A0076985727ACA70 FOREIGN KEY (parent_id) REFERENCES poste_organigramme (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE poste_organigramme ADD CONSTRAINT FK_A007698561190A32 FOREIGN KEY (club_id) REFERENCES club (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE poste_organigramme DROP FOREIGN KEY FK_A0076985727ACA70');
        $this->addSql('ALTER TABLE poste_organigramme DROP FOREIGN KEY FK_A007698561190A32');
        $this->addSql('DROP TABLE poste_organigramme');
    }
}
