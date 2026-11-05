<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Coordonnées des parents : un portable et un e-mail par parent, plus de téléphones maison.
 * Rien n'est perdu : un tél. maison va dans un portable parent resté vide, sinon dans le mémo ;
 * un champ « e-mail des parents » qui contenait deux adresses est réparti entre les deux parents.
 */
final class Version20261105200912 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Joueurs : portable et e-mail par parent, téléphones maison reclassés (portable vide ou mémo)';
    }

    public function up(Schema $schema): void
    {
        // Lecture avant le changement de structure (les requêtes ajoutées ci-dessous s'exécutent ensuite, dans l'ordre).
        $joueurs = $this->connection->fetchAllAssociative(
            'SELECT id, tel_maison_mere, tel_maison_pere, tel_portable1, tel_portable2, email_parents, memo FROM joueur
             WHERE tel_maison_mere <> \'\' OR tel_maison_pere <> \'\' OR email_parents <> \'\''
        );

        $this->addSql('ALTER TABLE joueur ADD email_parent2 VARCHAR(255) DEFAULT NULL, DROP tel_maison_mere, DROP tel_maison_pere, CHANGE email_parents email_parent1 VARCHAR(255) DEFAULT NULL');

        foreach ($joueurs as $j) {
            [$portable1, $portable2, $memo] = [$j['tel_portable1'], $j['tel_portable2'], []];
            $chiffres = fn (?string $tel) => preg_replace('/\D/', '', (string) $tel);

            foreach (['mère' => $j['tel_maison_mere'], 'père' => $j['tel_maison_pere']] as $qui => $tel) {
                $tel = trim((string) $tel);
                if ('' === $tel || \in_array($chiffres($tel), [$chiffres($portable1), $chiffres($portable2)], true)) {
                    continue; // vide, ou déjà présent dans un portable
                }
                if ('' === trim((string) $portable1)) {
                    $portable1 = $tel;
                } elseif ('' === trim((string) $portable2)) {
                    $portable2 = $tel;
                } else {
                    $memo[] = "Tél. maison ($qui) : $tel";
                }
            }

            $emails = array_values(array_unique(preg_split('/[\s,;\/]+/', mb_strtolower((string) $j['email_parents']), -1, \PREG_SPLIT_NO_EMPTY)));
            if (\count($emails) > 2) {
                $memo[] = 'Autres e-mails des parents : ' . implode(', ', \array_slice($emails, 2));
            }

            $memoComplet = trim((string) $j['memo']);
            if ($memo) {
                $memoComplet = ltrim($memoComplet . "\n" . implode("\n", $memo));
            }

            $this->addSql(
                'UPDATE joueur SET tel_portable1 = ?, tel_portable2 = ?, email_parent1 = ?, email_parent2 = ?, memo = ? WHERE id = ?',
                [$portable1 ?: null, $portable2 ?: null, $emails[0] ?? null, $emails[1] ?? null, '' === $memoComplet ? null : $memoComplet, $j['id']],
            );
        }
    }

    public function down(Schema $schema): void
    {
        // Structure seulement : les numéros reclassés restent dans les portables et le mémo.
        $this->addSql('ALTER TABLE joueur ADD tel_maison_mere VARCHAR(20) DEFAULT NULL, ADD tel_maison_pere VARCHAR(20) DEFAULT NULL, DROP email_parent2, CHANGE email_parent1 email_parents VARCHAR(255) DEFAULT NULL');
    }
}
