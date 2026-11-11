<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261111202111 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Tournois : fiche du tournoi, matchs relies au tournoi';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE tournoi (id INT AUTO_INCREMENT NOT NULL, nom VARCHAR(150) NOT NULL, debut DATE NOT NULL, fin DATE DEFAULT NULL, lieu VARCHAR(150) DEFAULT NULL, organisateur VARCHAR(150) DEFAULT NULL, format VARCHAR(255) DEFAULT NULL, transport VARCHAR(40) DEFAULT NULL, detail_transport VARCHAR(255) DEFAULT NULL, cout_inscription NUMERIC(8, 2) DEFAULT NULL, resultat VARCHAR(80) DEFAULT NULL, description LONGTEXT DEFAULT NULL, categorie_id INT NOT NULL, club_id INT NOT NULL, INDEX IDX_18AFD9DFBCF5E72D (categorie_id), INDEX IDX_18AFD9DF61190A32 (club_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE tournoi ADD CONSTRAINT FK_18AFD9DFBCF5E72D FOREIGN KEY (categorie_id) REFERENCES categorie (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE tournoi ADD CONSTRAINT FK_18AFD9DF61190A32 FOREIGN KEY (club_id) REFERENCES club (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE rencontre ADD tournoi_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE rencontre ADD CONSTRAINT FK_460C35EDF607770A FOREIGN KEY (tournoi_id) REFERENCES tournoi (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IDX_460C35EDF607770A ON rencontre (tournoi_id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE tournoi DROP FOREIGN KEY FK_18AFD9DFBCF5E72D');
        $this->addSql('ALTER TABLE tournoi DROP FOREIGN KEY FK_18AFD9DF61190A32');
        $this->addSql('DROP TABLE tournoi');
        $this->addSql('ALTER TABLE rencontre DROP FOREIGN KEY FK_460C35EDF607770A');
        $this->addSql('DROP INDEX IDX_460C35EDF607770A ON rencontre');
        $this->addSql('ALTER TABLE rencontre DROP tournoi_id');
    }
}
