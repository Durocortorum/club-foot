<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261103092818 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Buts : minutes d\'arrêt de jeu (« 45+2 », « 90+3 »)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE but ADD additionnel SMALLINT DEFAULT NULL');
        // Les buts notés 91 à 100 étaient déjà comptés en arrêt de jeu : ils deviennent « 90+1 » à « 90+10 ».
        $this->addSql('UPDATE but SET additionnel = minute - 90, minute = 90 WHERE minute BETWEEN 91 AND 100');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('UPDATE but SET minute = minute + additionnel WHERE additionnel IS NOT NULL');
        $this->addSql('ALTER TABLE but DROP additionnel');
    }
}
