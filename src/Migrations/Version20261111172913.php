<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261111172913 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Matchs de la poule : à venir (sans score) et numéro de journée';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE resultat_poule ADD journee SMALLINT DEFAULT NULL, CHANGE buts_domicile buts_domicile SMALLINT DEFAULT NULL, CHANGE buts_exterieur buts_exterieur SMALLINT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE resultat_poule DROP journee, CHANGE buts_domicile buts_domicile SMALLINT NOT NULL, CHANGE buts_exterieur buts_exterieur SMALLINT NOT NULL');
    }
}
