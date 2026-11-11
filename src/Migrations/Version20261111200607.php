<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261111200607 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Finances : tarifs et cotisations, paiements, recettes et depenses, contrats';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE contrat (id INT AUTO_INCREMENT NOT NULL, objet VARCHAR(150) NOT NULL, type VARCHAR(40) NOT NULL, partie VARCHAR(150) NOT NULL, debut DATE DEFAULT NULL, fin DATE DEFAULT NULL, montant NUMERIC(10, 2) DEFAULT NULL, periodicite VARCHAR(20) DEFAULT NULL, note LONGTEXT DEFAULT NULL, fichier VARCHAR(255) DEFAULT NULL, club_id INT NOT NULL, INDEX IDX_6034999361190A32 (club_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE cotisation (id INT AUTO_INCREMENT NOT NULL, saison VARCHAR(9) NOT NULL, montant NUMERIC(10, 2) DEFAULT NULL, remarque VARCHAR(255) DEFAULT NULL, joueur_id INT NOT NULL, club_id INT NOT NULL, UNIQUE INDEX cotisation_joueur_saison (joueur_id, saison), INDEX IDX_AE64D2EDA9E2D76C (joueur_id), INDEX IDX_AE64D2ED61190A32 (club_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE operation (id INT AUTO_INCREMENT NOT NULL, sens VARCHAR(10) NOT NULL, date DATE NOT NULL, rubrique VARCHAR(60) NOT NULL, libelle VARCHAR(255) NOT NULL, montant NUMERIC(10, 2) NOT NULL, nb_entrees INT DEFAULT NULL, prix_place NUMERIC(8, 2) DEFAULT NULL, mode VARCHAR(30) DEFAULT NULL, tiers VARCHAR(120) DEFAULT NULL, note VARCHAR(255) DEFAULT NULL, fichier VARCHAR(255) DEFAULT NULL, club_id INT NOT NULL, INDEX IDX_1981A66D61190A32 (club_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE paiement (id INT AUTO_INCREMENT NOT NULL, date DATE NOT NULL, montant NUMERIC(10, 2) NOT NULL, mode VARCHAR(30) NOT NULL, note VARCHAR(255) DEFAULT NULL, cotisation_id INT NOT NULL, club_id INT NOT NULL, INDEX IDX_B1DC7A1E3EAA84B1 (cotisation_id), INDEX IDX_B1DC7A1E61190A32 (club_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE tarif_cotisation (id INT AUTO_INCREMENT NOT NULL, saison VARCHAR(9) NOT NULL, montant NUMERIC(10, 2) NOT NULL, categorie_id INT NOT NULL, club_id INT NOT NULL, UNIQUE INDEX tarif_categorie_saison (categorie_id, saison), INDEX IDX_FF89D70FBCF5E72D (categorie_id), INDEX IDX_FF89D70F61190A32 (club_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE contrat ADD CONSTRAINT FK_6034999361190A32 FOREIGN KEY (club_id) REFERENCES club (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE cotisation ADD CONSTRAINT FK_AE64D2EDA9E2D76C FOREIGN KEY (joueur_id) REFERENCES joueur (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE cotisation ADD CONSTRAINT FK_AE64D2ED61190A32 FOREIGN KEY (club_id) REFERENCES club (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE operation ADD CONSTRAINT FK_1981A66D61190A32 FOREIGN KEY (club_id) REFERENCES club (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE paiement ADD CONSTRAINT FK_B1DC7A1E3EAA84B1 FOREIGN KEY (cotisation_id) REFERENCES cotisation (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE paiement ADD CONSTRAINT FK_B1DC7A1E61190A32 FOREIGN KEY (club_id) REFERENCES club (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE tarif_cotisation ADD CONSTRAINT FK_FF89D70FBCF5E72D FOREIGN KEY (categorie_id) REFERENCES categorie (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE tarif_cotisation ADD CONSTRAINT FK_FF89D70F61190A32 FOREIGN KEY (club_id) REFERENCES club (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE contrat DROP FOREIGN KEY FK_6034999361190A32');
        $this->addSql('ALTER TABLE cotisation DROP FOREIGN KEY FK_AE64D2EDA9E2D76C');
        $this->addSql('ALTER TABLE cotisation DROP FOREIGN KEY FK_AE64D2ED61190A32');
        $this->addSql('ALTER TABLE operation DROP FOREIGN KEY FK_1981A66D61190A32');
        $this->addSql('ALTER TABLE paiement DROP FOREIGN KEY FK_B1DC7A1E3EAA84B1');
        $this->addSql('ALTER TABLE paiement DROP FOREIGN KEY FK_B1DC7A1E61190A32');
        $this->addSql('ALTER TABLE tarif_cotisation DROP FOREIGN KEY FK_FF89D70FBCF5E72D');
        $this->addSql('ALTER TABLE tarif_cotisation DROP FOREIGN KEY FK_FF89D70F61190A32');
        $this->addSql('DROP TABLE contrat');
        $this->addSql('DROP TABLE cotisation');
        $this->addSql('DROP TABLE operation');
        $this->addSql('DROP TABLE paiement');
        $this->addSql('DROP TABLE tarif_cotisation');
    }
}
