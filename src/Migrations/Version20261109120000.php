<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261109120000 extends AbstractMigration
{
    /** Colonnes passées en majuscules : noms de famille et noms d'équipes. */
    private const COLONNES = [
        'joueur' => ['nom'],
        'utilisateur' => ['nom'],
        'educateur' => ['nom'],
        'dirigeant' => ['nom'],
        'joueur_observe' => ['nom', 'club_actuel'],
        'detection' => ['nom', 'club_actuel'],
        'contact_medical' => ['nom'],
        'categorie' => ['nom'],
        'rencontre' => ['equipe', 'domicile', 'exterieur'],
        'semaine_presence' => ['equipe'],
        'blessure' => ['equipe'],
    ];

    public function getDescription(): string
    {
        return 'Noms de famille et noms d\'équipes en majuscules';
    }

    public function up(Schema $schema): void
    {
        foreach (self::COLONNES as $table => $colonnes) {
            $this->addSql(sprintf(
                'UPDATE %s SET %s',
                $table,
                implode(', ', array_map(fn (string $c) => sprintf('%1$s = UPPER(TRIM(%1$s))', $c), $colonnes)),
            ));
        }
    }

    public function down(Schema $schema): void
    {
        // Les noms d'origine (minuscules) ne sont pas conservés : rien à rétablir.
    }
}
