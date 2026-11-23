<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261123133115 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Analyse de l\'adversaire : fiches (notes, préparation) par équipe et adversaire ; organisation de l\'adversaire et lien vidéo sur le match';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE fiche_adversaire (id INT AUTO_INCREMENT NOT NULL, cle VARCHAR(80) NOT NULL, nom VARCHAR(80) NOT NULL, points_forts LONGTEXT DEFAULT NULL, points_faibles LONGTEXT DEFAULT NULL, a_retenir LONGTEXT DEFAULT NULL, preparation LONGTEXT DEFAULT NULL, modifie_le DATETIME DEFAULT NULL, modifie_par_nom VARCHAR(170) DEFAULT NULL, categorie_id INT NOT NULL, club_id INT NOT NULL, UNIQUE INDEX UNIQ_DD12F08D61190A32BCF5E72D41401D17 (club_id, categorie_id, cle), INDEX IDX_DD12F08DBCF5E72D (categorie_id), INDEX IDX_DD12F08D61190A32 (club_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE fiche_adversaire ADD CONSTRAINT FK_DD12F08DBCF5E72D FOREIGN KEY (categorie_id) REFERENCES categorie (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE fiche_adversaire ADD CONSTRAINT FK_DD12F08D61190A32 FOREIGN KEY (club_id) REFERENCES club (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE rencontre ADD systeme_adverse VARCHAR(20) DEFAULT NULL, ADD lien_video VARCHAR(255) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE fiche_adversaire DROP FOREIGN KEY FK_DD12F08DBCF5E72D');
        $this->addSql('ALTER TABLE fiche_adversaire DROP FOREIGN KEY FK_DD12F08D61190A32');
        $this->addSql('DROP TABLE fiche_adversaire');
        $this->addSql('ALTER TABLE rencontre DROP systeme_adverse, DROP lien_video');
    }
}
