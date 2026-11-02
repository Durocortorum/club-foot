<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261102154135 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Structure initiale : clubs et leurs données';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE blessure (id INT AUTO_INCREMENT NOT NULL, date DATE DEFAULT NULL, date_reprise DATE DEFAULT NULL, type VARCHAR(60) DEFAULT NULL, zone VARCHAR(80) DEFAULT NULL, localisation VARCHAR(40) DEFAULT NULL, membre VARCHAR(30) DEFAULT NULL, lieu VARCHAR(20) DEFAULT NULL, avec_arret TINYINT(1) DEFAULT NULL, equipe VARCHAR(30) DEFAULT NULL, commentaire LONGTEXT DEFAULT NULL, joueur_id INT NOT NULL, club_id INT NOT NULL, INDEX IDX_56D071A2A9E2D76C (joueur_id), INDEX IDX_56D071A261190A32 (club_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE bulletin_scolaire (id INT AUTO_INCREMENT NOT NULL, saison VARCHAR(9) DEFAULT NULL, libelle VARCHAR(120) NOT NULL, fichier VARCHAR(255) DEFAULT NULL, origine VARCHAR(255) DEFAULT NULL, joueur_id INT NOT NULL, club_id INT NOT NULL, INDEX IDX_CE8A62C5A9E2D76C (joueur_id), INDEX IDX_CE8A62C561190A32 (club_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE but (id INT AUTO_INCREMENT NOT NULL, adverse TINYINT(1) NOT NULL, minute INT DEFAULT NULL, geste VARCHAR(30) DEFAULT NULL, conditions VARCHAR(60) DEFAULT NULL, phase VARCHAR(5) DEFAULT NULL, rencontre_id INT NOT NULL, buteur_id INT DEFAULT NULL, passeur_id INT DEFAULT NULL, club_id INT NOT NULL, INDEX IDX_B132FECA6CFC0818 (rencontre_id), INDEX IDX_B132FECA59365323 (buteur_id), INDEX IDX_B132FECA90B9A1AF (passeur_id), INDEX IDX_B132FECA61190A32 (club_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE categorie (id INT AUTO_INCREMENT NOT NULL, nom VARCHAR(30) NOT NULL, secteur VARCHAR(20) NOT NULL, ordre INT NOT NULL, club_id INT NOT NULL, UNIQUE INDEX UNIQ_497DD63461190A326C6E55B5 (club_id, nom), INDEX IDX_497DD63461190A32 (club_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE club (id INT AUTO_INCREMENT NOT NULL, nom VARCHAR(80) DEFAULT \'Club Foot\' NOT NULL, couleur VARCHAR(7) NOT NULL, couleur_texte VARCHAR(7) NOT NULL, logo VARCHAR(60) DEFAULT NULL, modifie_le DATETIME NOT NULL, PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE coach_saison (id INT AUTO_INCREMENT NOT NULL, saison VARCHAR(9) NOT NULL, categorie_id INT NOT NULL, educateur_id INT NOT NULL, club_id INT NOT NULL, UNIQUE INDEX UNIQ_1B7F9E33BCF5E72DC0D0D5866BFC1A0E (categorie_id, saison, educateur_id), INDEX IDX_1B7F9E33BCF5E72D (categorie_id), INDEX IDX_1B7F9E336BFC1A0E (educateur_id), INDEX IDX_1B7F9E3361190A32 (club_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE contact_medical (id INT AUTO_INCREMENT NOT NULL, nom VARCHAR(50) NOT NULL, prenom VARCHAR(50) DEFAULT NULL, profession VARCHAR(60) DEFAULT NULL, telephone VARCHAR(20) DEFAULT NULL, adresse VARCHAR(120) DEFAULT NULL, code_postal VARCHAR(10) DEFAULT NULL, ville VARCHAR(60) DEFAULT NULL, club_id INT NOT NULL, INDEX IDX_EDF426DB61190A32 (club_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE detection (id INT AUTO_INCREMENT NOT NULL, date_detection DATE DEFAULT NULL, nom VARCHAR(50) NOT NULL, prenom VARCHAR(50) DEFAULT NULL, date_naissance DATE DEFAULT NULL, lieu_naissance VARCHAR(80) DEFAULT NULL, photo VARCHAR(255) DEFAULT NULL, adresse VARCHAR(120) DEFAULT NULL, code_postal VARCHAR(10) DEFAULT NULL, ville VARCHAR(60) DEFAULT NULL, tel_fixe VARCHAR(20) DEFAULT NULL, tel_mobile VARCHAR(20) DEFAULT NULL, email VARCHAR(180) DEFAULT NULL, pied VARCHAR(20) DEFAULT NULL, poste VARCHAR(40) DEFAULT NULL, poste_secondaire VARCHAR(40) DEFAULT NULL, club_actuel VARCHAR(80) DEFAULT NULL, niveau VARCHAR(30) DEFAULT NULL, annee_premiere_licence VARCHAR(4) DEFAULT NULL, educateur_club VARCHAR(80) DEFAULT NULL, tel_educateur VARCHAR(20) DEFAULT NULL, nombre_seances SMALLINT DEFAULT NULL, numero_maillot VARCHAR(5) DEFAULT NULL, couleur_maillot VARCHAR(20) DEFAULT NULL, poids DOUBLE PRECISION DEFAULT NULL, taille DOUBLE PRECISION DEFAULT NULL, etablissement_scolaire VARCHAR(80) DEFAULT NULL, ville_scolaire VARCHAR(60) DEFAULT NULL, classe VARCHAR(20) DEFAULT NULL, section_sportive TINYINT(1) NOT NULL, retenu TINYINT(1) NOT NULL, memo LONGTEXT DEFAULT NULL, joueur_id INT DEFAULT NULL, club_id INT NOT NULL, INDEX IDX_A35F1C63543C272 (date_detection), INDEX IDX_A35F1C6A9E2D76C (joueur_id), INDEX IDX_A35F1C661190A32 (club_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE dirigeant (id INT AUTO_INCREMENT NOT NULL, nom VARCHAR(50) NOT NULL, prenom VARCHAR(50) NOT NULL, date_naissance DATE DEFAULT NULL, adresse VARCHAR(120) DEFAULT NULL, code_postal VARCHAR(10) DEFAULT NULL, ville VARCHAR(100) DEFAULT NULL, tel_fixe VARCHAR(20) DEFAULT NULL, tel_portable1 VARCHAR(20) DEFAULT NULL, tel_portable2 VARCHAR(20) DEFAULT NULL, tel_professionnel VARCHAR(20) DEFAULT NULL, email VARCHAR(180) DEFAULT NULL, licence VARCHAR(20) DEFAULT NULL, fonction VARCHAR(255) DEFAULT NULL, fonction2 VARCHAR(255) DEFAULT NULL, diplome VARCHAR(255) DEFAULT NULL, memo LONGTEXT DEFAULT NULL, photo VARCHAR(255) DEFAULT NULL, ancien TINYINT(1) NOT NULL, club_id INT NOT NULL, INDEX IDX_BEC71E7161190A32 (club_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE educateur (id INT AUTO_INCREMENT NOT NULL, nom VARCHAR(50) NOT NULL, prenom VARCHAR(50) NOT NULL, date_naissance DATE DEFAULT NULL, adresse VARCHAR(120) DEFAULT NULL, code_postal VARCHAR(10) DEFAULT NULL, ville VARCHAR(100) DEFAULT NULL, tel_fixe VARCHAR(20) DEFAULT NULL, tel_portable1 VARCHAR(20) DEFAULT NULL, tel_portable2 VARCHAR(20) DEFAULT NULL, tel_professionnel VARCHAR(20) DEFAULT NULL, email VARCHAR(180) DEFAULT NULL, licence VARCHAR(20) DEFAULT NULL, fonction VARCHAR(255) DEFAULT NULL, fonction2 VARCHAR(255) DEFAULT NULL, diplome VARCHAR(255) DEFAULT NULL, memo LONGTEXT DEFAULT NULL, photo VARCHAR(255) DEFAULT NULL, ancien TINYINT(1) NOT NULL, club_id INT NOT NULL, INDEX IDX_763C012261190A32 (club_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE exercice (id INT AUTO_INCREMENT NOT NULL, ordre INT NOT NULL, titre VARCHAR(120) NOT NULL, duree SMALLINT DEFAULT NULL, consignes LONGTEXT DEFAULT NULL, dessin JSON NOT NULL, seance_id INT NOT NULL, club_id INT NOT NULL, INDEX IDX_E418C74DE3797A94 (seance_id), INDEX IDX_E418C74D61190A32 (club_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE joueur (id INT AUTO_INCREMENT NOT NULL, nom VARCHAR(50) NOT NULL, prenom VARCHAR(50) NOT NULL, surnom VARCHAR(30) DEFAULT NULL, date_naissance DATE DEFAULT NULL, lieu_naissance VARCHAR(255) DEFAULT NULL, nationalite VARCHAR(50) DEFAULT NULL, photo VARCHAR(255) DEFAULT NULL, licence VARCHAR(20) DEFAULT NULL, poste VARCHAR(40) DEFAULT NULL, pied VARCHAR(20) DEFAULT NULL, date_arrivee DATE DEFAULT NULL, selectionne TINYINT(1) NOT NULL, ancien TINYINT(1) NOT NULL, memo LONGTEXT DEFAULT NULL, adresse VARCHAR(120) DEFAULT NULL, code_postal VARCHAR(10) DEFAULT NULL, ville VARCHAR(100) DEFAULT NULL, tel_joueur VARCHAR(20) DEFAULT NULL, tel_maison_mere VARCHAR(20) DEFAULT NULL, tel_maison_pere VARCHAR(20) DEFAULT NULL, tel_portable1 VARCHAR(20) DEFAULT NULL, tel_portable2 VARCHAR(20) DEFAULT NULL, email_joueur VARCHAR(255) DEFAULT NULL, email_parents VARCHAR(255) DEFAULT NULL, nom_pere VARCHAR(50) DEFAULT NULL, profession_pere VARCHAR(255) DEFAULT NULL, nom_mere VARCHAR(50) DEFAULT NULL, profession_mere VARCHAR(255) DEFAULT NULL, droit_image TINYINT(1) NOT NULL, transport_educateur TINYINT(1) NOT NULL, transport_parent TINYINT(1) NOT NULL, niveau_scolaire VARCHAR(255) DEFAULT NULL, ecole VARCHAR(255) DEFAULT NULL, etablissement VARCHAR(255) DEFAULT NULL, situation_scolaire VARCHAR(60) DEFAULT NULL, nom_assure VARCHAR(50) DEFAULT NULL, numero_secu VARCHAR(30) DEFAULT NULL, medecin VARCHAR(255) DEFAULT NULL, tel_medecin VARCHAR(30) DEFAULT NULL, renseignements_medicaux LONGTEXT DEFAULT NULL, categorie_id INT DEFAULT NULL, educateur_id INT DEFAULT NULL, club_id INT NOT NULL, INDEX IDX_FD71A9C56C6E55B5A625945B (nom, prenom), INDEX IDX_FD71A9C5BCF5E72D (categorie_id), INDEX IDX_FD71A9C56BFC1A0E (educateur_id), INDEX IDX_FD71A9C561190A32 (club_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE joueur_observe (id INT AUTO_INCREMENT NOT NULL, nom VARCHAR(50) NOT NULL, prenom VARCHAR(50) DEFAULT NULL, age SMALLINT DEFAULT NULL, poste VARCHAR(40) DEFAULT NULL, club_actuel VARCHAR(80) DEFAULT NULL, niveau VARCHAR(30) DEFAULT NULL, date_observation DATE DEFAULT NULL, evaluation JSON NOT NULL, commentaire LONGTEXT DEFAULT NULL, club_id INT NOT NULL, INDEX IDX_E00B538F61190A32 (club_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE mesure (id INT AUTO_INCREMENT NOT NULL, date DATE NOT NULL, poids DOUBLE PRECISION DEFAULT NULL, taille DOUBLE PRECISION DEFAULT NULL, taille_assis DOUBLE PRECISION DEFAULT NULL, pointure DOUBLE PRECISION DEFAULT NULL, joueur_id INT NOT NULL, club_id INT NOT NULL, INDEX IDX_5F1B6E70A9E2D76C (joueur_id), INDEX IDX_5F1B6E7061190A32 (club_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE participation (id INT AUTO_INCREMENT NOT NULL, numero SMALLINT DEFAULT NULL, poste VARCHAR(40) DEFAULT NULL, temps_jeu SMALLINT DEFAULT NULL, note DOUBLE PRECISION DEFAULT NULL, carton VARCHAR(5) DEFAULT NULL, appreciation LONGTEXT DEFAULT NULL, rencontre_id INT NOT NULL, joueur_id INT NOT NULL, club_id INT NOT NULL, UNIQUE INDEX UNIQ_AB55E24F6CFC0818A9E2D76C (rencontre_id, joueur_id), INDEX IDX_AB55E24F6CFC0818 (rencontre_id), INDEX IDX_AB55E24FA9E2D76C (joueur_id), INDEX IDX_AB55E24F61190A32 (club_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE presence (id INT AUTO_INCREMENT NOT NULL, creneaux JSON NOT NULL, semaine_id INT NOT NULL, joueur_id INT NOT NULL, club_id INT NOT NULL, UNIQUE INDEX UNIQ_6977C7A5122EEC90A9E2D76C (semaine_id, joueur_id), INDEX IDX_6977C7A5122EEC90 (semaine_id), INDEX IDX_6977C7A5A9E2D76C (joueur_id), INDEX IDX_6977C7A561190A32 (club_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE remplacement (id INT AUTO_INCREMENT NOT NULL, minute INT DEFAULT NULL, motif VARCHAR(20) DEFAULT NULL, rencontre_id INT NOT NULL, sortant_id INT DEFAULT NULL, entrant_id INT DEFAULT NULL, club_id INT NOT NULL, INDEX IDX_18EC0D1E6CFC0818 (rencontre_id), INDEX IDX_18EC0D1E138B4477 (sortant_id), INDEX IDX_18EC0D1E8BFF9D26 (entrant_id), INDEX IDX_18EC0D1E61190A32 (club_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE rencontre (id INT AUTO_INCREMENT NOT NULL, date DATE DEFAULT NULL, heure VARCHAR(10) DEFAULT NULL, equipe VARCHAR(30) DEFAULT NULL, competition VARCHAR(30) DEFAULT NULL, domicile VARCHAR(80) NOT NULL, exterieur VARCHAR(80) NOT NULL, buts_domicile INT DEFAULT NULL, buts_exterieur INT DEFAULT NULL, buts_domicile_mi_temps INT DEFAULT NULL, buts_exterieur_mi_temps INT DEFAULT NULL, lieu VARCHAR(120) DEFAULT NULL, terrain VARCHAR(40) DEFAULT NULL, meteo VARCHAR(40) DEFAULT NULL, vent VARCHAR(40) DEFAULT NULL, temperature VARCHAR(10) DEFAULT NULL, systeme VARCHAR(20) DEFAULT NULL, note DOUBLE PRECISION DEFAULT NULL, rapport LONGTEXT DEFAULT NULL, categorie_id INT DEFAULT NULL, club_id INT NOT NULL, INDEX IDX_460C35EDAA9E377A (date), INDEX IDX_460C35EDBCF5E72D (categorie_id), INDEX IDX_460C35ED61190A32 (club_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE sanction (id INT AUTO_INCREMENT NOT NULL, date DATE NOT NULL, motif VARCHAR(255) NOT NULL, lieu VARCHAR(30) DEFAULT NULL, sanction_sportive VARCHAR(120) DEFAULT NULL, sanction_scolaire VARCHAR(120) DEFAULT NULL, gravite SMALLINT DEFAULT NULL, joueur_id INT NOT NULL, club_id INT NOT NULL, INDEX IDX_6D6491AFA9E2D76C (joueur_id), INDEX IDX_6D6491AF61190A32 (club_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE seance (id INT AUTO_INCREMENT NOT NULL, date DATE NOT NULL, theme VARCHAR(120) NOT NULL, objectifs LONGTEXT DEFAULT NULL, categorie_id INT NOT NULL, club_id INT NOT NULL, INDEX IDX_DF7DFD0EAA9E377A (date), INDEX IDX_DF7DFD0EBCF5E72D (categorie_id), INDEX IDX_DF7DFD0E61190A32 (club_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE semaine_presence (id INT AUTO_INCREMENT NOT NULL, lundi DATE NOT NULL, equipe VARCHAR(30) DEFAULT NULL, categorie_id INT DEFAULT NULL, club_id INT NOT NULL, INDEX IDX_A2C7F987B1B7AC8A (lundi), INDEX IDX_A2C7F987BCF5E72D (categorie_id), INDEX IDX_A2C7F98761190A32 (club_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE test_physique (id INT AUTO_INCREMENT NOT NULL, date DATE NOT NULL, sprint10 DOUBLE PRECISION DEFAULT NULL, sprint20 DOUBLE PRECISION DEFAULT NULL, sprint40 DOUBLE PRECISION DEFAULT NULL, navette4x10 DOUBLE PRECISION DEFAULT NULL, vma DOUBLE PRECISION DEFAULT NULL, detente DOUBLE PRECISION DEFAULT NULL, cinq_foulees DOUBLE PRECISION DEFAULT NULL, frequence20 DOUBLE PRECISION DEFAULT NULL, amplitude20 DOUBLE PRECISION DEFAULT NULL, joueur_id INT NOT NULL, club_id INT NOT NULL, INDEX IDX_A9A23B1A9E2D76C (joueur_id), INDEX IDX_A9A23B161190A32 (club_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE utilisateur (id INT AUTO_INCREMENT NOT NULL, email VARCHAR(180) NOT NULL, nom VARCHAR(80) NOT NULL, prenom VARCHAR(80) NOT NULL, roles JSON NOT NULL, password VARCHAR(255) DEFAULT NULL, club_id INT NOT NULL, UNIQUE INDEX UNIQ_1D1C63B3E7927C74 (email), INDEX IDX_1D1C63B361190A32 (club_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE messenger_messages (id BIGINT AUTO_INCREMENT NOT NULL, body LONGTEXT NOT NULL, headers LONGTEXT NOT NULL, queue_name VARCHAR(190) NOT NULL, created_at DATETIME NOT NULL, available_at DATETIME NOT NULL, delivered_at DATETIME DEFAULT NULL, INDEX IDX_75EA56E0FB7336F0E3BD61CE16BA31DBBF396750 (queue_name, available_at, delivered_at, id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE blessure ADD CONSTRAINT FK_56D071A2A9E2D76C FOREIGN KEY (joueur_id) REFERENCES joueur (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE blessure ADD CONSTRAINT FK_56D071A261190A32 FOREIGN KEY (club_id) REFERENCES club (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE bulletin_scolaire ADD CONSTRAINT FK_CE8A62C5A9E2D76C FOREIGN KEY (joueur_id) REFERENCES joueur (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE bulletin_scolaire ADD CONSTRAINT FK_CE8A62C561190A32 FOREIGN KEY (club_id) REFERENCES club (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE but ADD CONSTRAINT FK_B132FECA6CFC0818 FOREIGN KEY (rencontre_id) REFERENCES rencontre (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE but ADD CONSTRAINT FK_B132FECA59365323 FOREIGN KEY (buteur_id) REFERENCES joueur (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE but ADD CONSTRAINT FK_B132FECA90B9A1AF FOREIGN KEY (passeur_id) REFERENCES joueur (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE but ADD CONSTRAINT FK_B132FECA61190A32 FOREIGN KEY (club_id) REFERENCES club (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE categorie ADD CONSTRAINT FK_497DD63461190A32 FOREIGN KEY (club_id) REFERENCES club (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE coach_saison ADD CONSTRAINT FK_1B7F9E33BCF5E72D FOREIGN KEY (categorie_id) REFERENCES categorie (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE coach_saison ADD CONSTRAINT FK_1B7F9E336BFC1A0E FOREIGN KEY (educateur_id) REFERENCES educateur (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE coach_saison ADD CONSTRAINT FK_1B7F9E3361190A32 FOREIGN KEY (club_id) REFERENCES club (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE contact_medical ADD CONSTRAINT FK_EDF426DB61190A32 FOREIGN KEY (club_id) REFERENCES club (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE detection ADD CONSTRAINT FK_A35F1C6A9E2D76C FOREIGN KEY (joueur_id) REFERENCES joueur (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE detection ADD CONSTRAINT FK_A35F1C661190A32 FOREIGN KEY (club_id) REFERENCES club (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE dirigeant ADD CONSTRAINT FK_BEC71E7161190A32 FOREIGN KEY (club_id) REFERENCES club (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE educateur ADD CONSTRAINT FK_763C012261190A32 FOREIGN KEY (club_id) REFERENCES club (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE exercice ADD CONSTRAINT FK_E418C74DE3797A94 FOREIGN KEY (seance_id) REFERENCES seance (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE exercice ADD CONSTRAINT FK_E418C74D61190A32 FOREIGN KEY (club_id) REFERENCES club (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE joueur ADD CONSTRAINT FK_FD71A9C5BCF5E72D FOREIGN KEY (categorie_id) REFERENCES categorie (id)');
        $this->addSql('ALTER TABLE joueur ADD CONSTRAINT FK_FD71A9C56BFC1A0E FOREIGN KEY (educateur_id) REFERENCES educateur (id)');
        $this->addSql('ALTER TABLE joueur ADD CONSTRAINT FK_FD71A9C561190A32 FOREIGN KEY (club_id) REFERENCES club (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE joueur_observe ADD CONSTRAINT FK_E00B538F61190A32 FOREIGN KEY (club_id) REFERENCES club (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE mesure ADD CONSTRAINT FK_5F1B6E70A9E2D76C FOREIGN KEY (joueur_id) REFERENCES joueur (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE mesure ADD CONSTRAINT FK_5F1B6E7061190A32 FOREIGN KEY (club_id) REFERENCES club (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE participation ADD CONSTRAINT FK_AB55E24F6CFC0818 FOREIGN KEY (rencontre_id) REFERENCES rencontre (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE participation ADD CONSTRAINT FK_AB55E24FA9E2D76C FOREIGN KEY (joueur_id) REFERENCES joueur (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE participation ADD CONSTRAINT FK_AB55E24F61190A32 FOREIGN KEY (club_id) REFERENCES club (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE presence ADD CONSTRAINT FK_6977C7A5122EEC90 FOREIGN KEY (semaine_id) REFERENCES semaine_presence (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE presence ADD CONSTRAINT FK_6977C7A5A9E2D76C FOREIGN KEY (joueur_id) REFERENCES joueur (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE presence ADD CONSTRAINT FK_6977C7A561190A32 FOREIGN KEY (club_id) REFERENCES club (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE remplacement ADD CONSTRAINT FK_18EC0D1E6CFC0818 FOREIGN KEY (rencontre_id) REFERENCES rencontre (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE remplacement ADD CONSTRAINT FK_18EC0D1E138B4477 FOREIGN KEY (sortant_id) REFERENCES joueur (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE remplacement ADD CONSTRAINT FK_18EC0D1E8BFF9D26 FOREIGN KEY (entrant_id) REFERENCES joueur (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE remplacement ADD CONSTRAINT FK_18EC0D1E61190A32 FOREIGN KEY (club_id) REFERENCES club (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE rencontre ADD CONSTRAINT FK_460C35EDBCF5E72D FOREIGN KEY (categorie_id) REFERENCES categorie (id)');
        $this->addSql('ALTER TABLE rencontre ADD CONSTRAINT FK_460C35ED61190A32 FOREIGN KEY (club_id) REFERENCES club (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE sanction ADD CONSTRAINT FK_6D6491AFA9E2D76C FOREIGN KEY (joueur_id) REFERENCES joueur (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE sanction ADD CONSTRAINT FK_6D6491AF61190A32 FOREIGN KEY (club_id) REFERENCES club (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE seance ADD CONSTRAINT FK_DF7DFD0EBCF5E72D FOREIGN KEY (categorie_id) REFERENCES categorie (id)');
        $this->addSql('ALTER TABLE seance ADD CONSTRAINT FK_DF7DFD0E61190A32 FOREIGN KEY (club_id) REFERENCES club (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE semaine_presence ADD CONSTRAINT FK_A2C7F987BCF5E72D FOREIGN KEY (categorie_id) REFERENCES categorie (id)');
        $this->addSql('ALTER TABLE semaine_presence ADD CONSTRAINT FK_A2C7F98761190A32 FOREIGN KEY (club_id) REFERENCES club (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE test_physique ADD CONSTRAINT FK_A9A23B1A9E2D76C FOREIGN KEY (joueur_id) REFERENCES joueur (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE test_physique ADD CONSTRAINT FK_A9A23B161190A32 FOREIGN KEY (club_id) REFERENCES club (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE utilisateur ADD CONSTRAINT FK_1D1C63B361190A32 FOREIGN KEY (club_id) REFERENCES club (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE blessure DROP FOREIGN KEY FK_56D071A2A9E2D76C');
        $this->addSql('ALTER TABLE blessure DROP FOREIGN KEY FK_56D071A261190A32');
        $this->addSql('ALTER TABLE bulletin_scolaire DROP FOREIGN KEY FK_CE8A62C5A9E2D76C');
        $this->addSql('ALTER TABLE bulletin_scolaire DROP FOREIGN KEY FK_CE8A62C561190A32');
        $this->addSql('ALTER TABLE but DROP FOREIGN KEY FK_B132FECA6CFC0818');
        $this->addSql('ALTER TABLE but DROP FOREIGN KEY FK_B132FECA59365323');
        $this->addSql('ALTER TABLE but DROP FOREIGN KEY FK_B132FECA90B9A1AF');
        $this->addSql('ALTER TABLE but DROP FOREIGN KEY FK_B132FECA61190A32');
        $this->addSql('ALTER TABLE categorie DROP FOREIGN KEY FK_497DD63461190A32');
        $this->addSql('ALTER TABLE coach_saison DROP FOREIGN KEY FK_1B7F9E33BCF5E72D');
        $this->addSql('ALTER TABLE coach_saison DROP FOREIGN KEY FK_1B7F9E336BFC1A0E');
        $this->addSql('ALTER TABLE coach_saison DROP FOREIGN KEY FK_1B7F9E3361190A32');
        $this->addSql('ALTER TABLE contact_medical DROP FOREIGN KEY FK_EDF426DB61190A32');
        $this->addSql('ALTER TABLE detection DROP FOREIGN KEY FK_A35F1C6A9E2D76C');
        $this->addSql('ALTER TABLE detection DROP FOREIGN KEY FK_A35F1C661190A32');
        $this->addSql('ALTER TABLE dirigeant DROP FOREIGN KEY FK_BEC71E7161190A32');
        $this->addSql('ALTER TABLE educateur DROP FOREIGN KEY FK_763C012261190A32');
        $this->addSql('ALTER TABLE exercice DROP FOREIGN KEY FK_E418C74DE3797A94');
        $this->addSql('ALTER TABLE exercice DROP FOREIGN KEY FK_E418C74D61190A32');
        $this->addSql('ALTER TABLE joueur DROP FOREIGN KEY FK_FD71A9C5BCF5E72D');
        $this->addSql('ALTER TABLE joueur DROP FOREIGN KEY FK_FD71A9C56BFC1A0E');
        $this->addSql('ALTER TABLE joueur DROP FOREIGN KEY FK_FD71A9C561190A32');
        $this->addSql('ALTER TABLE joueur_observe DROP FOREIGN KEY FK_E00B538F61190A32');
        $this->addSql('ALTER TABLE mesure DROP FOREIGN KEY FK_5F1B6E70A9E2D76C');
        $this->addSql('ALTER TABLE mesure DROP FOREIGN KEY FK_5F1B6E7061190A32');
        $this->addSql('ALTER TABLE participation DROP FOREIGN KEY FK_AB55E24F6CFC0818');
        $this->addSql('ALTER TABLE participation DROP FOREIGN KEY FK_AB55E24FA9E2D76C');
        $this->addSql('ALTER TABLE participation DROP FOREIGN KEY FK_AB55E24F61190A32');
        $this->addSql('ALTER TABLE presence DROP FOREIGN KEY FK_6977C7A5122EEC90');
        $this->addSql('ALTER TABLE presence DROP FOREIGN KEY FK_6977C7A5A9E2D76C');
        $this->addSql('ALTER TABLE presence DROP FOREIGN KEY FK_6977C7A561190A32');
        $this->addSql('ALTER TABLE remplacement DROP FOREIGN KEY FK_18EC0D1E6CFC0818');
        $this->addSql('ALTER TABLE remplacement DROP FOREIGN KEY FK_18EC0D1E138B4477');
        $this->addSql('ALTER TABLE remplacement DROP FOREIGN KEY FK_18EC0D1E8BFF9D26');
        $this->addSql('ALTER TABLE remplacement DROP FOREIGN KEY FK_18EC0D1E61190A32');
        $this->addSql('ALTER TABLE rencontre DROP FOREIGN KEY FK_460C35EDBCF5E72D');
        $this->addSql('ALTER TABLE rencontre DROP FOREIGN KEY FK_460C35ED61190A32');
        $this->addSql('ALTER TABLE sanction DROP FOREIGN KEY FK_6D6491AFA9E2D76C');
        $this->addSql('ALTER TABLE sanction DROP FOREIGN KEY FK_6D6491AF61190A32');
        $this->addSql('ALTER TABLE seance DROP FOREIGN KEY FK_DF7DFD0EBCF5E72D');
        $this->addSql('ALTER TABLE seance DROP FOREIGN KEY FK_DF7DFD0E61190A32');
        $this->addSql('ALTER TABLE semaine_presence DROP FOREIGN KEY FK_A2C7F987BCF5E72D');
        $this->addSql('ALTER TABLE semaine_presence DROP FOREIGN KEY FK_A2C7F98761190A32');
        $this->addSql('ALTER TABLE test_physique DROP FOREIGN KEY FK_A9A23B1A9E2D76C');
        $this->addSql('ALTER TABLE test_physique DROP FOREIGN KEY FK_A9A23B161190A32');
        $this->addSql('ALTER TABLE utilisateur DROP FOREIGN KEY FK_1D1C63B361190A32');
        $this->addSql('DROP TABLE blessure');
        $this->addSql('DROP TABLE bulletin_scolaire');
        $this->addSql('DROP TABLE but');
        $this->addSql('DROP TABLE categorie');
        $this->addSql('DROP TABLE club');
        $this->addSql('DROP TABLE coach_saison');
        $this->addSql('DROP TABLE contact_medical');
        $this->addSql('DROP TABLE detection');
        $this->addSql('DROP TABLE dirigeant');
        $this->addSql('DROP TABLE educateur');
        $this->addSql('DROP TABLE exercice');
        $this->addSql('DROP TABLE joueur');
        $this->addSql('DROP TABLE joueur_observe');
        $this->addSql('DROP TABLE mesure');
        $this->addSql('DROP TABLE participation');
        $this->addSql('DROP TABLE presence');
        $this->addSql('DROP TABLE remplacement');
        $this->addSql('DROP TABLE rencontre');
        $this->addSql('DROP TABLE sanction');
        $this->addSql('DROP TABLE seance');
        $this->addSql('DROP TABLE semaine_presence');
        $this->addSql('DROP TABLE test_physique');
        $this->addSql('DROP TABLE utilisateur');
        $this->addSql('DROP TABLE messenger_messages');
    }
}
