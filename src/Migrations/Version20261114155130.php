<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Paiement en ligne des inscriptions aux stages.
 */
final class Version20261114155130 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Paiement en ligne des stages';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE paiement_en_ligne ADD inscription_stage_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE paiement_en_ligne ADD CONSTRAINT FK_800D28888B0DAE36 FOREIGN KEY (inscription_stage_id) REFERENCES inscription_stage (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IDX_800D28888B0DAE36 ON paiement_en_ligne (inscription_stage_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE paiement_en_ligne DROP FOREIGN KEY FK_800D28888B0DAE36');
        $this->addSql('DROP INDEX IDX_800D28888B0DAE36 ON paiement_en_ligne');
        $this->addSql('ALTER TABLE paiement_en_ligne DROP inscription_stage_id');
    }
}
