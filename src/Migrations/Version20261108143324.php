<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Personnes à contacter : autorisation de voir les statistiques du joueur (non par défaut).
 */
final class Version20261108143324 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Personnes à contacter : voir les statistiques du joueur';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE proche ADD voit_statistiques TINYINT(1) DEFAULT 0 NOT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE proche DROP voit_statistiques');
    }
}
