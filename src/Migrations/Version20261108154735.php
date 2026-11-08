<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Coordonnées du club (adresse, téléphone, e-mail de contact, site), affichées en bas des e-mails.
 */
final class Version20261108154735 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Coordonnées du club pour les e-mails';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE club ADD adresse VARCHAR(255) DEFAULT NULL, ADD telephone VARCHAR(30) DEFAULT NULL, ADD email_contact VARCHAR(180) DEFAULT NULL, ADD site_web VARCHAR(255) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE club DROP adresse, DROP telephone, DROP email_contact, DROP site_web');
    }
}
