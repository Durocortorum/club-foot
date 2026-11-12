<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Montant des amendes de discipline et date de paiement.
 */
final class Version20261112214124 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Amendes de discipline';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE sanction ADD amende NUMERIC(8, 2) DEFAULT NULL, ADD amende_payee_le DATE DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE sanction DROP amende, DROP amende_payee_le');
    }
}
