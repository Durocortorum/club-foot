<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Abonnements des téléphones aux notifications (Web Push).
 */
final class Version20261112221722 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Abonnements aux notifications sur le telephone';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE abonnement_push (id INT AUTO_INCREMENT NOT NULL, adresse LONGTEXT NOT NULL, empreinte VARCHAR(64) NOT NULL, cle_p256dh VARCHAR(120) NOT NULL, cle_auth VARCHAR(60) NOT NULL, appareil VARCHAR(60) DEFAULT NULL, cree_le DATETIME NOT NULL, utilisateur_id INT NOT NULL, club_id INT NOT NULL, UNIQUE INDEX abonnement_push_adresse (empreinte), INDEX IDX_2609353EFB88E14F (utilisateur_id), INDEX IDX_2609353E61190A32 (club_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE abonnement_push ADD CONSTRAINT FK_2609353EFB88E14F FOREIGN KEY (utilisateur_id) REFERENCES utilisateur (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE abonnement_push ADD CONSTRAINT FK_2609353E61190A32 FOREIGN KEY (club_id) REFERENCES club (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE abonnement_push DROP FOREIGN KEY FK_2609353EFB88E14F');
        $this->addSql('ALTER TABLE abonnement_push DROP FOREIGN KEY FK_2609353E61190A32');
        $this->addSql('DROP TABLE abonnement_push');
    }
}
