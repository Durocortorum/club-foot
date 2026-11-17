<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261117210000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Convocation : date d\'envoi par joueur convoqué (envoyer seulement aux joueurs ajoutés après l\'envoi)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE convoque ADD envoye_le DATETIME DEFAULT NULL');
        // Convocations déjà envoyées : leurs convoqués actuels sont considérés comme prévenus.
        $this->addSql('UPDATE convoque c JOIN convocation v ON v.id = c.convocation_id SET c.envoye_le = v.envoyee_le WHERE v.envoyee_le IS NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE convoque DROP envoye_le');
    }
}
