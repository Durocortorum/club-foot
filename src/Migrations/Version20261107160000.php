<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Droits médicaux à durée limitée : échéance des droits Infirmerie et Visite médicale.
 * Les droits médicaux déjà donnés (hors administrateurs) reçoivent une échéance dans 30 jours :
 * un médecin du club (ou l'administrateur) les renouvelle s'il y a lieu.
 */
final class Version20261107160000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Échéance des droits médicaux (Infirmerie, Visite médicale) ; droits existants valables 30 jours';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE utilisateur ADD infirmerie_jusqu_au DATE DEFAULT NULL, ADD visite_medicale_jusqu_au DATE DEFAULT NULL');

        $echeance = (new \DateTimeImmutable('+30 days'))->format('Y-m-d');
        foreach ($this->connection->fetchAllAssociative('SELECT id, roles FROM utilisateur WHERE famille = 0') as $u) {
            $roles = json_decode($u['roles'], true) ?: [];
            if (\in_array('ROLE_ADMIN', $roles, true)) {
                continue;
            }
            $colonnes = [];
            if (array_intersect(['ROLE_INFIRMERIE', 'ROLE_INFIRMERIE_LECTURE'], $roles)) {
                $colonnes[] = 'infirmerie_jusqu_au = :fin';
            }
            if (array_intersect(['ROLE_VISITE_MEDICALE', 'ROLE_VISITE_MEDICALE_LECTURE'], $roles)) {
                $colonnes[] = 'visite_medicale_jusqu_au = :fin';
            }
            if ($colonnes) {
                $this->addSql('UPDATE utilisateur SET ' . implode(', ', $colonnes) . ' WHERE id = :id', ['fin' => $echeance, 'id' => $u['id']]);
            }
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE utilisateur DROP infirmerie_jusqu_au, DROP visite_medicale_jusqu_au');
    }
}
