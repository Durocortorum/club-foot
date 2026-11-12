<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Équipes féminines ; ancien club et autorisations des joueurs ; date du diplôme, permis et équipes de l'encadrement.
 */
final class Version20261112212229 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Equipes feminines, ancien club et autorisations, diplome, permis et equipes de l encadrement';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE dirigeant_categorie (dirigeant_id INT NOT NULL, categorie_id INT NOT NULL, INDEX IDX_79417ABFE233AF25 (dirigeant_id), INDEX IDX_79417ABFBCF5E72D (categorie_id), PRIMARY KEY(dirigeant_id, categorie_id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE educateur_categorie (educateur_id INT NOT NULL, categorie_id INT NOT NULL, INDEX IDX_5E813A46BFC1A0E (educateur_id), INDEX IDX_5E813A4BCF5E72D (categorie_id), PRIMARY KEY(educateur_id, categorie_id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE dirigeant_categorie ADD CONSTRAINT FK_79417ABFE233AF25 FOREIGN KEY (dirigeant_id) REFERENCES dirigeant (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE dirigeant_categorie ADD CONSTRAINT FK_79417ABFBCF5E72D FOREIGN KEY (categorie_id) REFERENCES categorie (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE educateur_categorie ADD CONSTRAINT FK_5E813A46BFC1A0E FOREIGN KEY (educateur_id) REFERENCES educateur (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE educateur_categorie ADD CONSTRAINT FK_5E813A4BCF5E72D FOREIGN KEY (categorie_id) REFERENCES categorie (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE administratif ADD date_diplome DATE DEFAULT NULL, ADD permis VARCHAR(30) DEFAULT NULL');
        $this->addSql('ALTER TABLE arbitre ADD date_diplome DATE DEFAULT NULL, ADD permis VARCHAR(30) DEFAULT NULL');
        $this->addSql('ALTER TABLE benevole ADD date_diplome DATE DEFAULT NULL, ADD permis VARCHAR(30) DEFAULT NULL');
        $this->addSql('ALTER TABLE categorie ADD feminine TINYINT(1) DEFAULT 0 NOT NULL');
        $this->addSql('ALTER TABLE dirigeant ADD date_diplome DATE DEFAULT NULL, ADD permis VARCHAR(30) DEFAULT NULL');
        $this->addSql('ALTER TABLE educateur ADD date_diplome DATE DEFAULT NULL, ADD permis VARCHAR(30) DEFAULT NULL');
        $this->addSql('ALTER TABLE joueur ADD ancien_club VARCHAR(120) DEFAULT NULL, ADD autorisation_soins TINYINT(1) DEFAULT 0 NOT NULL, ADD partir_seul TINYINT(1) DEFAULT 0 NOT NULL');
        // Catégories existantes au nom féminin (« FÉMININES », « U15 F », « U13 FILLES »).
        $this->addSql("UPDATE categorie SET feminine = 1 WHERE nom LIKE '%FÉMININ%' OR nom LIKE '%FEMININ%' OR nom LIKE '%FILLE%' OR nom LIKE '% F'");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE dirigeant_categorie DROP FOREIGN KEY FK_79417ABFE233AF25');
        $this->addSql('ALTER TABLE dirigeant_categorie DROP FOREIGN KEY FK_79417ABFBCF5E72D');
        $this->addSql('ALTER TABLE educateur_categorie DROP FOREIGN KEY FK_5E813A46BFC1A0E');
        $this->addSql('ALTER TABLE educateur_categorie DROP FOREIGN KEY FK_5E813A4BCF5E72D');
        $this->addSql('DROP TABLE dirigeant_categorie');
        $this->addSql('DROP TABLE educateur_categorie');
        $this->addSql('ALTER TABLE administratif DROP date_diplome, DROP permis');
        $this->addSql('ALTER TABLE arbitre DROP date_diplome, DROP permis');
        $this->addSql('ALTER TABLE benevole DROP date_diplome, DROP permis');
        $this->addSql('ALTER TABLE categorie DROP feminine');
        $this->addSql('ALTER TABLE dirigeant DROP date_diplome, DROP permis');
        $this->addSql('ALTER TABLE educateur DROP date_diplome, DROP permis');
        $this->addSql('ALTER TABLE joueur DROP ancien_club, DROP autorisation_soins, DROP partir_seul');
    }
}
