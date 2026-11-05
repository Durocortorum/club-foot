<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261105195518 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Comptes famille (joueurs, parents) : joueurs suivis et lien d\'activation';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE utilisateur_joueur (utilisateur_id INT NOT NULL, joueur_id INT NOT NULL, INDEX IDX_9CDC934EFB88E14F (utilisateur_id), INDEX IDX_9CDC934EA9E2D76C (joueur_id), PRIMARY KEY(utilisateur_id, joueur_id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE utilisateur_joueur ADD CONSTRAINT FK_9CDC934EFB88E14F FOREIGN KEY (utilisateur_id) REFERENCES utilisateur (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE utilisateur_joueur ADD CONSTRAINT FK_9CDC934EA9E2D76C FOREIGN KEY (joueur_id) REFERENCES joueur (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE utilisateur ADD famille TINYINT(1) DEFAULT 0 NOT NULL, ADD jeton_activation VARCHAR(64) DEFAULT NULL, ADD jeton_expire_le DATETIME DEFAULT NULL, ADD invite_le DATETIME DEFAULT NULL');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_1D1C63B35F772A56 ON utilisateur (jeton_activation)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE utilisateur_joueur DROP FOREIGN KEY FK_9CDC934EFB88E14F');
        $this->addSql('ALTER TABLE utilisateur_joueur DROP FOREIGN KEY FK_9CDC934EA9E2D76C');
        $this->addSql('DROP TABLE utilisateur_joueur');
        $this->addSql('DROP INDEX UNIQ_1D1C63B35F772A56 ON utilisateur');
        $this->addSql('ALTER TABLE utilisateur DROP famille, DROP jeton_activation, DROP jeton_expire_le, DROP invite_le');
    }
}
