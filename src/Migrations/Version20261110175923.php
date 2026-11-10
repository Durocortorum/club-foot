<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261110175923 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Inscription à un stage faite par le joueur lui-même';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE inscription_stage ADD par_le_joueur TINYINT(1) DEFAULT 0 NOT NULL');
        // Inscriptions déjà faites depuis un espace pour un joueur majeur : c'est lui qui a l'accès, donc lui qui s'est inscrit.
        $this->addSql('UPDATE inscription_stage i JOIN joueur j ON j.id = i.joueur_id SET i.par_le_joueur = 1 WHERE i.par_famille = 1 AND j.date_naissance <= DATE_SUB(CURDATE(), INTERVAL 18 YEAR)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE inscription_stage DROP par_le_joueur');
    }
}
