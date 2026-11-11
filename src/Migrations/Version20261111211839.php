<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261111211839 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Catalogue des tests (types de tests) et resultats des joueurs';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE resultat_test (id INT AUTO_INCREMENT NOT NULL, date DATE NOT NULL, valeur DOUBLE PRECISION NOT NULL, note VARCHAR(255) DEFAULT NULL, joueur_id INT NOT NULL, test_id INT NOT NULL, club_id INT NOT NULL, INDEX resultat_test_joueur_date (joueur_id, date), INDEX IDX_7ECAF22DA9E2D76C (joueur_id), INDEX IDX_7ECAF22D1E5D0459 (test_id), INDEX IDX_7ECAF22D61190A32 (club_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE type_test (id INT AUTO_INCREMENT NOT NULL, code VARCHAR(30) DEFAULT NULL, nom VARCHAR(80) NOT NULL, famille VARCHAR(30) NOT NULL, unite VARCHAR(15) NOT NULL, plus_bas_mieux TINYINT(1) NOT NULL, objectif VARCHAR(255) DEFAULT NULL, protocole LONGTEXT DEFAULT NULL, materiel VARCHAR(255) DEFAULT NULL, age_min SMALLINT DEFAULT NULL, age_max SMALLINT DEFAULT NULL, ordre SMALLINT NOT NULL, club_id INT NOT NULL, INDEX IDX_F70BB0461190A32 (club_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE resultat_test ADD CONSTRAINT FK_7ECAF22DA9E2D76C FOREIGN KEY (joueur_id) REFERENCES joueur (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE resultat_test ADD CONSTRAINT FK_7ECAF22D1E5D0459 FOREIGN KEY (test_id) REFERENCES type_test (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE resultat_test ADD CONSTRAINT FK_7ECAF22D61190A32 FOREIGN KEY (club_id) REFERENCES club (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE type_test ADD CONSTRAINT FK_F70BB0461190A32 FOREIGN KEY (club_id) REFERENCES club (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE resultat_test DROP FOREIGN KEY FK_7ECAF22DA9E2D76C');
        $this->addSql('ALTER TABLE resultat_test DROP FOREIGN KEY FK_7ECAF22D1E5D0459');
        $this->addSql('ALTER TABLE resultat_test DROP FOREIGN KEY FK_7ECAF22D61190A32');
        $this->addSql('ALTER TABLE type_test DROP FOREIGN KEY FK_F70BB0461190A32');
        $this->addSql('DROP TABLE resultat_test');
        $this->addSql('DROP TABLE type_test');
    }
}
