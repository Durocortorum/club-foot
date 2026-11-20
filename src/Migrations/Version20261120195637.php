<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261120195637 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Matchs : éducateur responsable et personnes désignées par équipe (seuls à modifier les rapports), trace de la dernière modification';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE categorie_redacteur_match (categorie_id INT NOT NULL, utilisateur_id INT NOT NULL, INDEX IDX_2FD7A863BCF5E72D (categorie_id), INDEX IDX_2FD7A863FB88E14F (utilisateur_id), PRIMARY KEY(categorie_id, utilisateur_id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE categorie_redacteur_match ADD CONSTRAINT FK_2FD7A863BCF5E72D FOREIGN KEY (categorie_id) REFERENCES categorie (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE categorie_redacteur_match ADD CONSTRAINT FK_2FD7A863FB88E14F FOREIGN KEY (utilisateur_id) REFERENCES utilisateur (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE categorie ADD responsable_matchs_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE categorie ADD CONSTRAINT FK_497DD63475AD257E FOREIGN KEY (responsable_matchs_id) REFERENCES utilisateur (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IDX_497DD63475AD257E ON categorie (responsable_matchs_id)');
        $this->addSql('ALTER TABLE rencontre ADD modifie_le DATETIME DEFAULT NULL, ADD modifie_par_nom VARCHAR(170) DEFAULT NULL, ADD modifie_par_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE rencontre ADD CONSTRAINT FK_460C35ED553B2554 FOREIGN KEY (modifie_par_id) REFERENCES utilisateur (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IDX_460C35ED553B2554 ON rencontre (modifie_par_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE categorie_redacteur_match DROP FOREIGN KEY FK_2FD7A863BCF5E72D');
        $this->addSql('ALTER TABLE categorie_redacteur_match DROP FOREIGN KEY FK_2FD7A863FB88E14F');
        $this->addSql('DROP TABLE categorie_redacteur_match');
        $this->addSql('ALTER TABLE categorie DROP FOREIGN KEY FK_497DD63475AD257E');
        $this->addSql('DROP INDEX IDX_497DD63475AD257E ON categorie');
        $this->addSql('ALTER TABLE categorie DROP responsable_matchs_id');
        $this->addSql('ALTER TABLE rencontre DROP FOREIGN KEY FK_460C35ED553B2554');
        $this->addSql('DROP INDEX IDX_460C35ED553B2554 ON rencontre');
        $this->addSql('ALTER TABLE rencontre DROP modifie_le, DROP modifie_par_nom, DROP modifie_par_id');
    }
}
