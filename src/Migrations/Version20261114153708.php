<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Paiement en ligne (HelloAsso) : option du club, compte HelloAsso du club, paiements lancés par les familles.
 */
final class Version20261114153708 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Paiement en ligne HelloAsso';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE connexion_hello_asso (id INT AUTO_INCREMENT NOT NULL, slug VARCHAR(120) NOT NULL, client_id VARCHAR(120) NOT NULL, secret_chiffre LONGTEXT NOT NULL, test TINYINT(1) DEFAULT 0 NOT NULL, nom_association VARCHAR(200) DEFAULT NULL, verifiee_le DATETIME DEFAULT NULL, echeances_max SMALLINT DEFAULT 1 NOT NULL, jeton_notification VARCHAR(40) NOT NULL, access_token LONGTEXT DEFAULT NULL, access_expire DATETIME DEFAULT NULL, refresh_token LONGTEXT DEFAULT NULL, refresh_expire DATETIME DEFAULT NULL, club_id INT NOT NULL, UNIQUE INDEX connexion_helloasso_club (club_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE paiement_en_ligne (id INT AUTO_INCREMENT NOT NULL, objet VARCHAR(20) NOT NULL, libelle VARCHAR(250) NOT NULL, montant INT NOT NULL, echeances SMALLINT NOT NULL, checkout_intent_id INT DEFAULT NULL, commande_hello_asso INT DEFAULT NULL, statut VARCHAR(15) NOT NULL, paiements_enregistres JSON NOT NULL, cree_le DATETIME NOT NULL, paye_le DATETIME DEFAULT NULL, joueur_id INT NOT NULL, cotisation_id INT DEFAULT NULL, payeur_id INT DEFAULT NULL, club_id INT NOT NULL, INDEX paiement_en_ligne_intention (checkout_intent_id), INDEX IDX_800D2888A9E2D76C (joueur_id), INDEX IDX_800D28883EAA84B1 (cotisation_id), INDEX IDX_800D2888422667C5 (payeur_id), INDEX IDX_800D288861190A32 (club_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE connexion_hello_asso ADD CONSTRAINT FK_90E916A961190A32 FOREIGN KEY (club_id) REFERENCES club (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE paiement_en_ligne ADD CONSTRAINT FK_800D2888A9E2D76C FOREIGN KEY (joueur_id) REFERENCES joueur (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE paiement_en_ligne ADD CONSTRAINT FK_800D28883EAA84B1 FOREIGN KEY (cotisation_id) REFERENCES cotisation (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE paiement_en_ligne ADD CONSTRAINT FK_800D2888422667C5 FOREIGN KEY (payeur_id) REFERENCES utilisateur (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE paiement_en_ligne ADD CONSTRAINT FK_800D288861190A32 FOREIGN KEY (club_id) REFERENCES club (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE club ADD paiement_en_ligne TINYINT(1) DEFAULT 0 NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE connexion_hello_asso DROP FOREIGN KEY FK_90E916A961190A32');
        $this->addSql('ALTER TABLE paiement_en_ligne DROP FOREIGN KEY FK_800D2888A9E2D76C');
        $this->addSql('ALTER TABLE paiement_en_ligne DROP FOREIGN KEY FK_800D28883EAA84B1');
        $this->addSql('ALTER TABLE paiement_en_ligne DROP FOREIGN KEY FK_800D2888422667C5');
        $this->addSql('ALTER TABLE paiement_en_ligne DROP FOREIGN KEY FK_800D288861190A32');
        $this->addSql('DROP TABLE connexion_hello_asso');
        $this->addSql('DROP TABLE paiement_en_ligne');
        $this->addSql('ALTER TABLE club DROP paiement_en_ligne');
    }
}
