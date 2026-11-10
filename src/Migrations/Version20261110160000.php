<?php

declare(strict_types=1);

namespace App\Migrations;

use App\Entity\Prenom;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261110160000 extends AbstractMigration
{
    private const TABLES = [
        'joueur', 'utilisateur', 'educateur', 'dirigeant', 'administratif', 'benevole', 'arbitre',
        'contact_medical', 'detection', 'joueur_observe', 'candidature',
    ];

    public function getDescription(): string
    {
        return 'Prénoms écrits « Jean-Pierre » (ceux saisis tout en majuscules ou tout en minuscules)';
    }

    public function up(Schema $schema): void
    {
        foreach (self::TABLES as $table) {
            foreach ($this->connection->fetchAllKeyValue("SELECT id, prenom FROM $table WHERE prenom IS NOT NULL") as $id => $prenom) {
                $nouveau = Prenom::formater($prenom);
                if ($nouveau !== $prenom) {
                    $this->addSql("UPDATE $table SET prenom = ? WHERE id = ?", [$nouveau, $id]);
                }
            }
        }
    }

    public function down(Schema $schema): void
    {
        // L'écriture d'origine n'est pas conservée : rien à rétablir.
    }
}
