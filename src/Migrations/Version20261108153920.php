<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Entraînements supprimés par l'éducateur : masqués partout, gardés pour ne pas être recréés par le planning.
 */
final class Version20261108153920 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Entraînements supprimés (masqués, non recréés)';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE entrainement ADD supprime TINYINT(1) DEFAULT 0 NOT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE entrainement DROP supprime');
    }
}
