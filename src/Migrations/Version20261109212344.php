<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261109212344 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Événements du club (fête, repas, assemblée générale…) et équipes concernées';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE evenement (id INT AUTO_INCREMENT NOT NULL, titre VARCHAR(120) NOT NULL, type VARCHAR(30) NOT NULL, debut DATETIME NOT NULL, fin DATETIME DEFAULT NULL, lieu VARCHAR(150) DEFAULT NULL, description LONGTEXT DEFAULT NULL, visible_familles TINYINT(1) NOT NULL, club_id INT NOT NULL, INDEX IDX_B26681E61190A32 (club_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE evenement_categorie (evenement_id INT NOT NULL, categorie_id INT NOT NULL, INDEX IDX_E723DAE5FD02F13 (evenement_id), INDEX IDX_E723DAE5BCF5E72D (categorie_id), PRIMARY KEY(evenement_id, categorie_id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE evenement ADD CONSTRAINT FK_B26681E61190A32 FOREIGN KEY (club_id) REFERENCES club (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE evenement_categorie ADD CONSTRAINT FK_E723DAE5FD02F13 FOREIGN KEY (evenement_id) REFERENCES evenement (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE evenement_categorie ADD CONSTRAINT FK_E723DAE5BCF5E72D FOREIGN KEY (categorie_id) REFERENCES categorie (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE evenement DROP FOREIGN KEY FK_B26681E61190A32');
        $this->addSql('ALTER TABLE evenement_categorie DROP FOREIGN KEY FK_E723DAE5FD02F13');
        $this->addSql('ALTER TABLE evenement_categorie DROP FOREIGN KEY FK_E723DAE5BCF5E72D');
        $this->addSql('DROP TABLE evenement');
        $this->addSql('DROP TABLE evenement_categorie');
    }
}
