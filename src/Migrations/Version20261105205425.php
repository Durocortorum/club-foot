<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261105205425 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Convocations aux matchs et réponses des familles';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE convocation (id INT AUTO_INCREMENT NOT NULL, rdv_heure VARCHAR(10) DEFAULT NULL, rdv_lieu VARCHAR(120) DEFAULT NULL, message LONGTEXT DEFAULT NULL, envoyee_le DATETIME DEFAULT NULL, rencontre_id INT NOT NULL, club_id INT NOT NULL, UNIQUE INDEX UNIQ_C03B3F5F6CFC0818 (rencontre_id), INDEX IDX_C03B3F5F61190A32 (club_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE convoque (id INT AUTO_INCREMENT NOT NULL, reponse VARCHAR(10) DEFAULT NULL, motif VARCHAR(120) DEFAULT NULL, repondu_le DATETIME DEFAULT NULL, repondu_par VARCHAR(180) DEFAULT NULL, convocation_id INT NOT NULL, joueur_id INT NOT NULL, club_id INT NOT NULL, UNIQUE INDEX UNIQ_5B32C22EE8746F65A9E2D76C (convocation_id, joueur_id), INDEX IDX_5B32C22EE8746F65 (convocation_id), INDEX IDX_5B32C22EA9E2D76C (joueur_id), INDEX IDX_5B32C22E61190A32 (club_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE convocation ADD CONSTRAINT FK_C03B3F5F6CFC0818 FOREIGN KEY (rencontre_id) REFERENCES rencontre (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE convocation ADD CONSTRAINT FK_C03B3F5F61190A32 FOREIGN KEY (club_id) REFERENCES club (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE convoque ADD CONSTRAINT FK_5B32C22EE8746F65 FOREIGN KEY (convocation_id) REFERENCES convocation (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE convoque ADD CONSTRAINT FK_5B32C22EA9E2D76C FOREIGN KEY (joueur_id) REFERENCES joueur (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE convoque ADD CONSTRAINT FK_5B32C22E61190A32 FOREIGN KEY (club_id) REFERENCES club (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE convocation DROP FOREIGN KEY FK_C03B3F5F6CFC0818');
        $this->addSql('ALTER TABLE convocation DROP FOREIGN KEY FK_C03B3F5F61190A32');
        $this->addSql('ALTER TABLE convoque DROP FOREIGN KEY FK_5B32C22EE8746F65');
        $this->addSql('ALTER TABLE convoque DROP FOREIGN KEY FK_5B32C22EA9E2D76C');
        $this->addSql('ALTER TABLE convoque DROP FOREIGN KEY FK_5B32C22E61190A32');
        $this->addSql('DROP TABLE convocation');
        $this->addSql('DROP TABLE convoque');
    }
}
