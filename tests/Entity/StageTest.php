<?php

namespace App\Tests\Entity;

use App\Entity\Stage;
use PHPUnit\Framework\TestCase;

class StageTest extends TestCase
{
    public function testInscriptionsJusquAuPremierJour(): void
    {
        $aujourdhui = new \DateTimeImmutable('today');
        $stage = (new Stage())->setDebut($aujourdhui)->setInscriptionsFamilles(true);

        // Sans date limite : ouvert jusqu'au premier jour inclus, fermé le lendemain.
        self::assertTrue($stage->isInscriptionOuverte($aujourdhui));
        self::assertFalse($stage->isInscriptionOuverte($aujourdhui->modify('+1 day')));

        // Date limite indiquée : c'est elle qui compte.
        $stage->setDateLimite($aujourdhui->modify('-1 day'));
        self::assertFalse($stage->isInscriptionOuverte($aujourdhui));

        // Stage obligatoire ou inscriptions non ouvertes aux familles : jamais par l'espace.
        $stage->setDateLimite(null)->setObligatoire(true);
        self::assertFalse($stage->isInscriptionOuverte($aujourdhui));
        $stage->setObligatoire(false)->setInscriptionsFamilles(false);
        self::assertFalse($stage->isInscriptionOuverte($aujourdhui));
    }
}
