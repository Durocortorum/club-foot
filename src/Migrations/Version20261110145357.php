<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261110145357 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Tâche confiée à un joueur (sa famille)';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE tache ADD joueur_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE tache ADD CONSTRAINT FK_93872075A9E2D76C FOREIGN KEY (joueur_id) REFERENCES joueur (id) ON DELETE CASCADE');
        $this->addSql('CREATE INDEX IDX_93872075A9E2D76C ON tache (joueur_id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE tache DROP FOREIGN KEY FK_93872075A9E2D76C');
        $this->addSql('DROP INDEX IDX_93872075A9E2D76C ON tache');
        $this->addSql('ALTER TABLE tache DROP joueur_id');
    }
}
