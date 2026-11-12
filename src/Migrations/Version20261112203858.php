<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Numéros utiles saisis par le club (stade, comptable, district, urgences…).
 */
final class Version20261112203858 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Numeros utiles du club';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE contact_utile (id INT AUTO_INCREMENT NOT NULL, rubrique VARCHAR(20) NOT NULL, libelle VARCHAR(100) NOT NULL, nom VARCHAR(100) DEFAULT NULL, telephone VARCHAR(25) DEFAULT NULL, telephone2 VARCHAR(25) DEFAULT NULL, email VARCHAR(180) DEFAULT NULL, adresse VARCHAR(200) DEFAULT NULL, note VARCHAR(255) DEFAULT NULL, club_id INT NOT NULL, INDEX IDX_8DF1FEB561190A32 (club_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE contact_utile ADD CONSTRAINT FK_8DF1FEB561190A32 FOREIGN KEY (club_id) REFERENCES club (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE contact_utile DROP FOREIGN KEY FK_8DF1FEB561190A32');
        $this->addSql('DROP TABLE contact_utile');
    }
}
