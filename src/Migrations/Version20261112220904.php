<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Déplacement relié à son match (affiché dans la convocation), heure et lieu de départ.
 */
final class Version20261112220904 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Deplacement relie au match, heure et lieu de depart';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE deplacement ADD heure_depart VARCHAR(10) DEFAULT NULL, ADD lieu_depart VARCHAR(120) DEFAULT NULL, ADD rencontre_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE deplacement ADD CONSTRAINT FK_1296FAC26CFC0818 FOREIGN KEY (rencontre_id) REFERENCES rencontre (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IDX_1296FAC26CFC0818 ON deplacement (rencontre_id)');
        // Déplacements déjà notés depuis un match (« Match … ») : reliés au seul match de l'équipe ce jour-là.
        $this->addSql("UPDATE deplacement d JOIN rencontre r ON r.categorie_id = d.categorie_id AND r.date = d.date AND r.club_id = d.club_id
            SET d.rencontre_id = r.id
            WHERE d.objet LIKE 'Match %'
              AND (SELECT COUNT(*) FROM rencontre r2 WHERE r2.categorie_id = d.categorie_id AND r2.date = d.date) = 1");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE deplacement DROP FOREIGN KEY FK_1296FAC26CFC0818');
        $this->addSql('DROP INDEX IDX_1296FAC26CFC0818 ON deplacement');
        $this->addSql('ALTER TABLE deplacement DROP heure_depart, DROP lieu_depart, DROP rencontre_id');
    }
}
