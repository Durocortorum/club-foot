<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261105224325 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Planning des entraînements : créneaux récurrents, entraînements datés, absences signalées';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE absence_entrainement (id INT AUTO_INCREMENT NOT NULL, motif VARCHAR(40) NOT NULL, commentaire VARCHAR(120) DEFAULT NULL, declaree_le DATETIME NOT NULL, declaree_par VARCHAR(180) NOT NULL, entrainement_id INT NOT NULL, joueur_id INT NOT NULL, club_id INT NOT NULL, UNIQUE INDEX UNIQ_4B8EEB8FA15E8FDA9E2D76C (entrainement_id, joueur_id), INDEX IDX_4B8EEB8FA15E8FD (entrainement_id), INDEX IDX_4B8EEB8FA9E2D76C (joueur_id), INDEX IDX_4B8EEB8F61190A32 (club_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE creneau_entrainement (id INT AUTO_INCREMENT NOT NULL, jour SMALLINT NOT NULL, heure_debut VARCHAR(5) NOT NULL, heure_fin VARCHAR(5) DEFAULT NULL, lieu VARCHAR(120) DEFAULT NULL, categorie_id INT NOT NULL, club_id INT NOT NULL, INDEX IDX_93B8F4CEBCF5E72D (categorie_id), INDEX IDX_93B8F4CE61190A32 (club_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE entrainement (id INT AUTO_INCREMENT NOT NULL, date DATE NOT NULL, heure_debut VARCHAR(5) NOT NULL, heure_fin VARCHAR(5) DEFAULT NULL, lieu VARCHAR(120) DEFAULT NULL, annule TINYINT(1) DEFAULT 0 NOT NULL, motif_annulation VARCHAR(120) DEFAULT NULL, categorie_id INT NOT NULL, creneau_id INT DEFAULT NULL, club_id INT NOT NULL, INDEX IDX_A27444E5AA9E377A (date), UNIQUE INDEX UNIQ_A27444E57D0729A9AA9E377A (creneau_id, date), INDEX IDX_A27444E5BCF5E72D (categorie_id), INDEX IDX_A27444E57D0729A9 (creneau_id), INDEX IDX_A27444E561190A32 (club_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE absence_entrainement ADD CONSTRAINT FK_4B8EEB8FA15E8FD FOREIGN KEY (entrainement_id) REFERENCES entrainement (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE absence_entrainement ADD CONSTRAINT FK_4B8EEB8FA9E2D76C FOREIGN KEY (joueur_id) REFERENCES joueur (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE absence_entrainement ADD CONSTRAINT FK_4B8EEB8F61190A32 FOREIGN KEY (club_id) REFERENCES club (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE creneau_entrainement ADD CONSTRAINT FK_93B8F4CEBCF5E72D FOREIGN KEY (categorie_id) REFERENCES categorie (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE creneau_entrainement ADD CONSTRAINT FK_93B8F4CE61190A32 FOREIGN KEY (club_id) REFERENCES club (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE entrainement ADD CONSTRAINT FK_A27444E5BCF5E72D FOREIGN KEY (categorie_id) REFERENCES categorie (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE entrainement ADD CONSTRAINT FK_A27444E57D0729A9 FOREIGN KEY (creneau_id) REFERENCES creneau_entrainement (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE entrainement ADD CONSTRAINT FK_A27444E561190A32 FOREIGN KEY (club_id) REFERENCES club (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE absence_entrainement DROP FOREIGN KEY FK_4B8EEB8FA15E8FD');
        $this->addSql('ALTER TABLE absence_entrainement DROP FOREIGN KEY FK_4B8EEB8FA9E2D76C');
        $this->addSql('ALTER TABLE absence_entrainement DROP FOREIGN KEY FK_4B8EEB8F61190A32');
        $this->addSql('ALTER TABLE creneau_entrainement DROP FOREIGN KEY FK_93B8F4CEBCF5E72D');
        $this->addSql('ALTER TABLE creneau_entrainement DROP FOREIGN KEY FK_93B8F4CE61190A32');
        $this->addSql('ALTER TABLE entrainement DROP FOREIGN KEY FK_A27444E5BCF5E72D');
        $this->addSql('ALTER TABLE entrainement DROP FOREIGN KEY FK_A27444E57D0729A9');
        $this->addSql('ALTER TABLE entrainement DROP FOREIGN KEY FK_A27444E561190A32');
        $this->addSql('DROP TABLE absence_entrainement');
        $this->addSql('DROP TABLE creneau_entrainement');
        $this->addSql('DROP TABLE entrainement');
    }
}
