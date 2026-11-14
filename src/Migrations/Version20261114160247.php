<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Boutique du club : articles, commandes et leurs lignes ; paiement en ligne des commandes.
 */
final class Version20261114160247 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Boutique du club';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE article_boutique (id INT AUTO_INCREMENT NOT NULL, nom VARCHAR(120) NOT NULL, description LONGTEXT DEFAULT NULL, prix NUMERIC(8, 2) NOT NULL, tailles JSON NOT NULL, stock JSON DEFAULT NULL, photo VARCHAR(60) DEFAULT NULL, en_vente TINYINT(1) DEFAULT 1 NOT NULL, ordre INT DEFAULT 0 NOT NULL, club_id INT NOT NULL, INDEX IDX_F4CAC83F61190A32 (club_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE commande_boutique (id INT AUTO_INCREMENT NOT NULL, client VARCHAR(160) NOT NULL, statut VARCHAR(15) NOT NULL, total INT NOT NULL, mode_reglement VARCHAR(40) DEFAULT NULL, note VARCHAR(255) DEFAULT NULL, cree_le DATETIME NOT NULL, payee_le DATETIME DEFAULT NULL, remise_le DATETIME DEFAULT NULL, compte_id INT DEFAULT NULL, joueur_id INT DEFAULT NULL, club_id INT NOT NULL, INDEX IDX_770224CEF2C56620 (compte_id), INDEX IDX_770224CEA9E2D76C (joueur_id), INDEX IDX_770224CE61190A32 (club_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE ligne_commande (id INT AUTO_INCREMENT NOT NULL, libelle VARCHAR(120) NOT NULL, taille VARCHAR(20) NOT NULL, quantite INT NOT NULL, prix_unitaire INT NOT NULL, commande_id INT NOT NULL, article_id INT DEFAULT NULL, INDEX IDX_3170B74B82EA2E54 (commande_id), INDEX IDX_3170B74B7294869C (article_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE article_boutique ADD CONSTRAINT FK_F4CAC83F61190A32 FOREIGN KEY (club_id) REFERENCES club (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE commande_boutique ADD CONSTRAINT FK_770224CEF2C56620 FOREIGN KEY (compte_id) REFERENCES utilisateur (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE commande_boutique ADD CONSTRAINT FK_770224CEA9E2D76C FOREIGN KEY (joueur_id) REFERENCES joueur (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE commande_boutique ADD CONSTRAINT FK_770224CE61190A32 FOREIGN KEY (club_id) REFERENCES club (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE ligne_commande ADD CONSTRAINT FK_3170B74B82EA2E54 FOREIGN KEY (commande_id) REFERENCES commande_boutique (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE ligne_commande ADD CONSTRAINT FK_3170B74B7294869C FOREIGN KEY (article_id) REFERENCES article_boutique (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE paiement_en_ligne ADD commande_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE paiement_en_ligne ADD CONSTRAINT FK_800D288882EA2E54 FOREIGN KEY (commande_id) REFERENCES commande_boutique (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IDX_800D288882EA2E54 ON paiement_en_ligne (commande_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE article_boutique DROP FOREIGN KEY FK_F4CAC83F61190A32');
        $this->addSql('ALTER TABLE commande_boutique DROP FOREIGN KEY FK_770224CEF2C56620');
        $this->addSql('ALTER TABLE commande_boutique DROP FOREIGN KEY FK_770224CEA9E2D76C');
        $this->addSql('ALTER TABLE commande_boutique DROP FOREIGN KEY FK_770224CE61190A32');
        $this->addSql('ALTER TABLE ligne_commande DROP FOREIGN KEY FK_3170B74B82EA2E54');
        $this->addSql('ALTER TABLE ligne_commande DROP FOREIGN KEY FK_3170B74B7294869C');
        $this->addSql('DROP TABLE article_boutique');
        $this->addSql('DROP TABLE commande_boutique');
        $this->addSql('DROP TABLE ligne_commande');
        $this->addSql('ALTER TABLE paiement_en_ligne DROP FOREIGN KEY FK_800D288882EA2E54');
        $this->addSql('DROP INDEX IDX_800D288882EA2E54 ON paiement_en_ligne');
        $this->addSql('ALTER TABLE paiement_en_ligne DROP commande_id');
    }
}
