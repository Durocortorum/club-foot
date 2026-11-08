<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261108211838 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Messagerie interne : messages aux familles, destinataires, réponses';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE message (id INT AUTO_INCREMENT NOT NULL, auteur_nom VARCHAR(120) NOT NULL, sujet VARCHAR(120) NOT NULL, corps LONGTEXT NOT NULL, important TINYINT(1) NOT NULL, cible VARCHAR(20) NOT NULL, cible_libelle VARCHAR(160) NOT NULL, cree_le DATETIME NOT NULL, auteur_id INT DEFAULT NULL, categorie_id INT DEFAULT NULL, rencontre_id INT DEFAULT NULL, club_id INT NOT NULL, INDEX IDX_B6BD307FAF820231 (cree_le), INDEX IDX_B6BD307F60BB6FE6 (auteur_id), INDEX IDX_B6BD307FBCF5E72D (categorie_id), INDEX IDX_B6BD307F6CFC0818 (rencontre_id), INDEX IDX_B6BD307F61190A32 (club_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE message_destinataire (id INT AUTO_INCREMENT NOT NULL, lu_le DATETIME DEFAULT NULL, message_id INT NOT NULL, joueur_id INT NOT NULL, compte_id INT DEFAULT NULL, club_id INT NOT NULL, INDEX IDX_3AA8CA74F2C566205571E50E (compte_id, lu_le), INDEX IDX_3AA8CA74537A1329 (message_id), INDEX IDX_3AA8CA74A9E2D76C (joueur_id), INDEX IDX_3AA8CA74F2C56620 (compte_id), INDEX IDX_3AA8CA7461190A32 (club_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE message_reponse (id INT AUTO_INCREMENT NOT NULL, auteur_nom VARCHAR(120) NOT NULL, de_lencadrement TINYINT(1) NOT NULL, corps LONGTEXT NOT NULL, cree_le DATETIME NOT NULL, lu TINYINT(1) NOT NULL, message_id INT NOT NULL, famille_id INT NOT NULL, auteur_id INT DEFAULT NULL, club_id INT NOT NULL, INDEX IDX_CF3EE4CB537A1329 (message_id), INDEX IDX_CF3EE4CB97A77B84 (famille_id), INDEX IDX_CF3EE4CB60BB6FE6 (auteur_id), INDEX IDX_CF3EE4CB61190A32 (club_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE message ADD CONSTRAINT FK_B6BD307F60BB6FE6 FOREIGN KEY (auteur_id) REFERENCES utilisateur (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE message ADD CONSTRAINT FK_B6BD307FBCF5E72D FOREIGN KEY (categorie_id) REFERENCES categorie (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE message ADD CONSTRAINT FK_B6BD307F6CFC0818 FOREIGN KEY (rencontre_id) REFERENCES rencontre (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE message ADD CONSTRAINT FK_B6BD307F61190A32 FOREIGN KEY (club_id) REFERENCES club (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE message_destinataire ADD CONSTRAINT FK_3AA8CA74537A1329 FOREIGN KEY (message_id) REFERENCES message (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE message_destinataire ADD CONSTRAINT FK_3AA8CA74A9E2D76C FOREIGN KEY (joueur_id) REFERENCES joueur (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE message_destinataire ADD CONSTRAINT FK_3AA8CA74F2C56620 FOREIGN KEY (compte_id) REFERENCES utilisateur (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE message_destinataire ADD CONSTRAINT FK_3AA8CA7461190A32 FOREIGN KEY (club_id) REFERENCES club (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE message_reponse ADD CONSTRAINT FK_CF3EE4CB537A1329 FOREIGN KEY (message_id) REFERENCES message (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE message_reponse ADD CONSTRAINT FK_CF3EE4CB97A77B84 FOREIGN KEY (famille_id) REFERENCES utilisateur (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE message_reponse ADD CONSTRAINT FK_CF3EE4CB60BB6FE6 FOREIGN KEY (auteur_id) REFERENCES utilisateur (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE message_reponse ADD CONSTRAINT FK_CF3EE4CB61190A32 FOREIGN KEY (club_id) REFERENCES club (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE message DROP FOREIGN KEY FK_B6BD307F60BB6FE6');
        $this->addSql('ALTER TABLE message DROP FOREIGN KEY FK_B6BD307FBCF5E72D');
        $this->addSql('ALTER TABLE message DROP FOREIGN KEY FK_B6BD307F6CFC0818');
        $this->addSql('ALTER TABLE message DROP FOREIGN KEY FK_B6BD307F61190A32');
        $this->addSql('ALTER TABLE message_destinataire DROP FOREIGN KEY FK_3AA8CA74537A1329');
        $this->addSql('ALTER TABLE message_destinataire DROP FOREIGN KEY FK_3AA8CA74A9E2D76C');
        $this->addSql('ALTER TABLE message_destinataire DROP FOREIGN KEY FK_3AA8CA74F2C56620');
        $this->addSql('ALTER TABLE message_destinataire DROP FOREIGN KEY FK_3AA8CA7461190A32');
        $this->addSql('ALTER TABLE message_reponse DROP FOREIGN KEY FK_CF3EE4CB537A1329');
        $this->addSql('ALTER TABLE message_reponse DROP FOREIGN KEY FK_CF3EE4CB97A77B84');
        $this->addSql('ALTER TABLE message_reponse DROP FOREIGN KEY FK_CF3EE4CB60BB6FE6');
        $this->addSql('ALTER TABLE message_reponse DROP FOREIGN KEY FK_CF3EE4CB61190A32');
        $this->addSql('DROP TABLE message');
        $this->addSql('DROP TABLE message_destinataire');
        $this->addSql('DROP TABLE message_reponse');
    }
}
