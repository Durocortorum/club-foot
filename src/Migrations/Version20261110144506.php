<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261110144506 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Tâches confiées aux membres de l\'encadrement (personne, catégorie, objet, échéance)';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE tache (id INT AUTO_INCREMENT NOT NULL, objet VARCHAR(150) NOT NULL, description LONGTEXT DEFAULT NULL, echeance DATE DEFAULT NULL, urgente TINYINT(1) NOT NULL, statut VARCHAR(20) NOT NULL, compte_rendu LONGTEXT DEFAULT NULL, cree_le DATETIME NOT NULL, faite_le DATETIME DEFAULT NULL, responsable_id INT DEFAULT NULL, categorie_id INT DEFAULT NULL, cree_par_id INT DEFAULT NULL, club_id INT NOT NULL, INDEX IDX_93872075E564F0BF (statut), INDEX IDX_9387207553C59D72 (responsable_id), INDEX IDX_93872075BCF5E72D (categorie_id), INDEX IDX_93872075FC29C013 (cree_par_id), INDEX IDX_9387207561190A32 (club_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE tache ADD CONSTRAINT FK_9387207553C59D72 FOREIGN KEY (responsable_id) REFERENCES utilisateur (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE tache ADD CONSTRAINT FK_93872075BCF5E72D FOREIGN KEY (categorie_id) REFERENCES categorie (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE tache ADD CONSTRAINT FK_93872075FC29C013 FOREIGN KEY (cree_par_id) REFERENCES utilisateur (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE tache ADD CONSTRAINT FK_9387207561190A32 FOREIGN KEY (club_id) REFERENCES club (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE tache DROP FOREIGN KEY FK_9387207553C59D72');
        $this->addSql('ALTER TABLE tache DROP FOREIGN KEY FK_93872075BCF5E72D');
        $this->addSql('ALTER TABLE tache DROP FOREIGN KEY FK_93872075FC29C013');
        $this->addSql('ALTER TABLE tache DROP FOREIGN KEY FK_9387207561190A32');
        $this->addSql('DROP TABLE tache');
    }
}
