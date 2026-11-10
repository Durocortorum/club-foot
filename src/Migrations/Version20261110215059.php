<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261110215059 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Classement recalculé : scores des autres matchs de la poule et points corrigés à la main';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE resultat_poule (id INT AUTO_INCREMENT NOT NULL, saison VARCHAR(9) NOT NULL, date DATE NOT NULL, domicile VARCHAR(80) NOT NULL, exterieur VARCHAR(80) NOT NULL, buts_domicile SMALLINT NOT NULL, buts_exterieur SMALLINT NOT NULL, categorie_id INT NOT NULL, club_id INT NOT NULL, INDEX IDX_B6D04E92C0D0D586 (saison), INDEX IDX_B6D04E92BCF5E72D (categorie_id), INDEX IDX_B6D04E9261190A32 (club_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE resultat_poule ADD CONSTRAINT FK_B6D04E92BCF5E72D FOREIGN KEY (categorie_id) REFERENCES categorie (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE resultat_poule ADD CONSTRAINT FK_B6D04E9261190A32 FOREIGN KEY (club_id) REFERENCES club (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE classement ADD ajustements JSON DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE resultat_poule DROP FOREIGN KEY FK_B6D04E92BCF5E72D');
        $this->addSql('ALTER TABLE resultat_poule DROP FOREIGN KEY FK_B6D04E9261190A32');
        $this->addSql('DROP TABLE resultat_poule');
        $this->addSql('ALTER TABLE classement DROP ajustements');
    }
}
