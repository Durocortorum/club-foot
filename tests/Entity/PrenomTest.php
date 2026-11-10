<?php

namespace App\Tests\Entity;

use App\Entity\Prenom;
use PHPUnit\Framework\TestCase;

class PrenomTest extends TestCase
{
    public function testEcritureDesPrenoms(): void
    {
        self::assertSame('Christophe', Prenom::formater('CHRISTOPHE'));
        self::assertSame('Marc', Prenom::formater('marc'));
        self::assertSame('Jean-Pierre', Prenom::formater('JEAN-PIERRE'));
        self::assertSame('Anne Sophie', Prenom::formater('  anne   sophie '));
        self::assertSame('Élodie', Prenom::formater('ÉLODIE'));
        self::assertSame('DeShawn', Prenom::formater('DeShawn'), 'casse mixte gardée');
        self::assertNull(Prenom::formater(null));
    }
}
