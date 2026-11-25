<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261125230000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Analyse de l\'adversaire : système habituel, pressing, relance, coups de pied arrêtés, joueurs dangereux, vidéos';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE fiche_adversaire ADD systeme_habituel VARCHAR(20) DEFAULT NULL, ADD jeu JSON DEFAULT NULL, ADD joueurs_dangereux JSON DEFAULT NULL, ADD videos JSON DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE fiche_adversaire DROP systeme_habituel, DROP jeu, DROP joueurs_dangereux, DROP videos');
    }
}
