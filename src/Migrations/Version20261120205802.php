<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261120205802 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Responsable de chaque secteur (voit le secteur, modifie les matchs et convocations de toutes ses équipes)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE responsable_secteur (id INT AUTO_INCREMENT NOT NULL, secteur VARCHAR(20) NOT NULL, utilisateur_id INT NOT NULL, club_id INT NOT NULL, UNIQUE INDEX UNIQ_9C8A99EC61190A328045251F (club_id, secteur), INDEX IDX_9C8A99ECFB88E14F (utilisateur_id), INDEX IDX_9C8A99EC61190A32 (club_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE responsable_secteur ADD CONSTRAINT FK_9C8A99ECFB88E14F FOREIGN KEY (utilisateur_id) REFERENCES utilisateur (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE responsable_secteur ADD CONSTRAINT FK_9C8A99EC61190A32 FOREIGN KEY (club_id) REFERENCES club (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE responsable_secteur DROP FOREIGN KEY FK_9C8A99ECFB88E14F');
        $this->addSql('ALTER TABLE responsable_secteur DROP FOREIGN KEY FK_9C8A99EC61190A32');
        $this->addSql('DROP TABLE responsable_secteur');
    }
}
