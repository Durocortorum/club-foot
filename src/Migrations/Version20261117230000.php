<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261117230000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Feuille de match : case « titulaire » par joueur (plus seulement les numéros 1 à 11)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE participation ADD titulaire TINYINT(1) DEFAULT 1 NOT NULL');
        // Feuilles existantes : même résultat qu'avant (sur le terrain au coup d'envoi : sans numéro ou n° 1 à 11).
        $this->addSql('UPDATE participation SET titulaire = CASE WHEN numero IS NULL OR numero <= 11 THEN 1 ELSE 0 END');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE participation DROP titulaire');
    }
}
