<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261123144511 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Tableaux tactiques des matchs (causerie et préparation) : schéma animé, consignes, ordre, partage avec les familles';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE tableau_tactique (id INT AUTO_INCREMENT NOT NULL, titre VARCHAR(80) NOT NULL, consignes LONGTEXT DEFAULT NULL, dessin JSON NOT NULL, ordre INT NOT NULL, partage TINYINT(1) DEFAULT 0 NOT NULL, modifie_le DATETIME DEFAULT NULL, modifie_par_nom VARCHAR(170) DEFAULT NULL, rencontre_id INT NOT NULL, club_id INT NOT NULL, INDEX IDX_8297D1306CFC0818 (rencontre_id), INDEX IDX_8297D13061190A32 (club_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE tableau_tactique ADD CONSTRAINT FK_8297D1306CFC0818 FOREIGN KEY (rencontre_id) REFERENCES rencontre (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE tableau_tactique ADD CONSTRAINT FK_8297D13061190A32 FOREIGN KEY (club_id) REFERENCES club (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE tableau_tactique DROP FOREIGN KEY FK_8297D1306CFC0818');
        $this->addSql('ALTER TABLE tableau_tactique DROP FOREIGN KEY FK_8297D13061190A32');
        $this->addSql('DROP TABLE tableau_tactique');
    }
}
