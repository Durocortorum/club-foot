<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261122210959 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Plateaux : équipes qui jouent des plateaux (cochées pour l\'école de foot), petits matchs et clubs présents d\'un plateau';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE categorie ADD plateaux TINYINT(1) DEFAULT 0 NOT NULL');
        // École de foot (U7, U9, U11) : des plateaux plutôt que des matchs ; réglable ensuite équipe par équipe.
        $this->addSql("UPDATE categorie SET plateaux = 1 WHERE secteur = 'ecole_foot'");
        $this->addSql('ALTER TABLE rencontre ADD matchs_plateau JSON DEFAULT NULL, ADD clubs_plateau JSON DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE categorie DROP plateaux');
        $this->addSql('ALTER TABLE rencontre DROP matchs_plateau, DROP clubs_plateau');
    }
}
