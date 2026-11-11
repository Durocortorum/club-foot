<?php

namespace App\Tests\Entity;

use App\Entity\Blessure;
use PHPUnit\Framework\TestCase;

/**
 * Reprise de l'entraînement et reprise des matchs : où en est le joueur aujourd'hui.
 */
class BlessureTest extends TestCase
{
    public function testStatutSelonLesDeuxReprises(): void
    {
        $jour = fn (string $m) => new \DateTimeImmutable('today ' . $m);
        $b = (new Blessure())->setDate($jour('-20 days'));
        self::assertSame('arret', $b->getStatut(), 'sans reprise : arrêté');

        $b->setDateReprise($jour('+3 days'));
        self::assertSame('arret', $b->getStatut(), 'reprise de l\'entraînement à venir');

        $b->setDateReprise($jour('-2 days'))->setDateRepriseMatch($jour('+5 days'));
        self::assertSame('entrainement', $b->getStatut());
        self::assertTrue($b->isEnCours(), 'pas encore apte aux matchs : toujours dans les indisponibles');

        $b->setDateRepriseMatch($jour('+0 days'));
        self::assertSame('apte', $b->getStatut());
        self::assertFalse($b->isEnCours());

        // Sans date de reprise des matchs : il rejoue en même temps qu'il reprend l'entraînement.
        $b->setDateRepriseMatch(null);
        self::assertSame('apte', $b->getStatut());
        self::assertEquals($jour('-2 days'), $b->getRepriseMatchEffective());
    }
}
