<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261111204408 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Transport : conducteurs et controle du permis';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE conducteur (id INT AUTO_INCREMENT NOT NULL, nom VARCHAR(120) NOT NULL, telephone VARCHAR(30) DEFAULT NULL, categories_permis VARCHAR(30) DEFAULT NULL, numero_permis VARCHAR(40) DEFAULT NULL, permis_expire_le DATE DEFAULT NULL, controle_le DATE DEFAULT NULL, controle_par VARCHAR(120) DEFAULT NULL, minibus TINYINT(1) DEFAULT 0 NOT NULL, note VARCHAR(255) DEFAULT NULL, club_id INT NOT NULL, UNIQUE INDEX conducteur_club_nom (club_id, nom), INDEX IDX_2367714361190A32 (club_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE conducteur ADD CONSTRAINT FK_2367714361190A32 FOREIGN KEY (club_id) REFERENCES club (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE conducteur DROP FOREIGN KEY FK_2367714361190A32');
        $this->addSql('DROP TABLE conducteur');
    }
}
