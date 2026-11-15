<?php

namespace App\Tests;

use App\EventSubscriber\EmailsDemoBloques;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Event\MessageEvent;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

/** Aucun e-mail ne part vers les adresses @demo.org du club de démonstration (domaine qui appartient à un tiers). */
class EmailsDemoBloquesTest extends TestCase
{
    private function evenement(string ...$destinataires): MessageEvent
    {
        $email = (new Email())->from('club@example.com')->to(...$destinataires)->text('x');
        $event = new MessageEvent($email, new Envelope(new Address('club@example.com'), array_map(fn ($d) => new Address($d), $destinataires)), 'smtp');
        (new EmailsDemoBloques())->filtrer($event);

        return $event;
    }

    public function testDemoBloque(): void
    {
        self::assertTrue($this->evenement('parent@demo.org')->isRejected());
        self::assertTrue($this->evenement('joueur@DEMO.ORG', 'famille.martin3@demo.org')->isRejected());
    }

    public function testAutresAdressesGardees(): void
    {
        $event = $this->evenement('parent@demo.org', 'vraie@exemple.fr');
        self::assertFalse($event->isRejected());
        self::assertSame(['vraie@exemple.fr'], array_map(fn ($a) => $a->getAddress(), $event->getEnvelope()->getRecipients()));
        self::assertFalse($this->evenement('quelquun@mondemo.org')->isRejected());
    }
}
