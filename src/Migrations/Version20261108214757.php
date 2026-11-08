<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261108214757 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Messagerie : messages entre membres de l\'encadrement (destinataire sans joueur)';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE message_destinataire CHANGE joueur_id joueur_id INT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE message_destinataire CHANGE joueur_id joueur_id INT NOT NULL');
    }
}
