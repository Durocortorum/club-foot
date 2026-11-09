<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261109210041 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Classement du championnat par équipe et par saison (recopié de la FFF ou de Transfermarkt)';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE classement (id INT AUTO_INCREMENT NOT NULL, saison VARCHAR(9) NOT NULL, lignes JSON NOT NULL, mis_ajour_le DATETIME NOT NULL, categorie_id INT NOT NULL, club_id INT NOT NULL, UNIQUE INDEX UNIQ_55EE9D6DBCF5E72DC0D0D586 (categorie_id, saison), INDEX IDX_55EE9D6DBCF5E72D (categorie_id), INDEX IDX_55EE9D6D61190A32 (club_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE classement ADD CONSTRAINT FK_55EE9D6DBCF5E72D FOREIGN KEY (categorie_id) REFERENCES categorie (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE classement ADD CONSTRAINT FK_55EE9D6D61190A32 FOREIGN KEY (club_id) REFERENCES club (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE classement DROP FOREIGN KEY FK_55EE9D6DBCF5E72D');
        $this->addSql('ALTER TABLE classement DROP FOREIGN KEY FK_55EE9D6D61190A32');
        $this->addSql('DROP TABLE classement');
    }
}
