<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Personnes à contacter d'un joueur majeur (gérées par le joueur dans son espace ; reprises des parents de la fiche).
 */
final class Version20261108141553 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Personnes à contacter des joueurs majeurs';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE proche (id INT AUTO_INCREMENT NOT NULL, nom VARCHAR(120) NOT NULL, lien VARCHAR(60) DEFAULT NULL, telephone VARCHAR(30) DEFAULT NULL, email VARCHAR(180) DEFAULT NULL, joueur_id INT NOT NULL, club_id INT NOT NULL, INDEX IDX_80DAF999A9E2D76C (joueur_id), INDEX IDX_80DAF99961190A32 (club_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE proche ADD CONSTRAINT FK_80DAF999A9E2D76C FOREIGN KEY (joueur_id) REFERENCES joueur (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE proche ADD CONSTRAINT FK_80DAF99961190A32 FOREIGN KEY (club_id) REFERENCES club (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE proche DROP FOREIGN KEY FK_80DAF999A9E2D76C');
        $this->addSql('ALTER TABLE proche DROP FOREIGN KEY FK_80DAF99961190A32');
        $this->addSql('DROP TABLE proche');
    }
}
