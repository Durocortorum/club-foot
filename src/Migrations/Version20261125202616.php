<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261125202616 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Match de coupe : suite en cas d\'égalité (tirs au but, prolongations, nul) et score de la séance de tirs au but';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE rencontre ADD egalite VARCHAR(15) DEFAULT NULL, ADD tab_domicile SMALLINT DEFAULT NULL, ADD tab_exterieur SMALLINT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE rencontre DROP egalite, DROP tab_domicile, DROP tab_exterieur');
    }
}
