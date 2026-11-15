<?php

namespace App\EventSubscriber;

use App\Command\ClubDemoCommand;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Mailer\Event\MessageEvent;

/**
 * Club de démonstration : ses comptes et ses fiches utilisent des adresses @demo.org. Ce domaine existe et
 * appartient à quelqu'un d'autre : aucun e-mail n'y est envoyé (convocation, message, invitation, mot de passe…).
 * Les autres destinataires d'un même e-mail le reçoivent normalement.
 */
class EmailsDemoBloques implements EventSubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return [MessageEvent::class => ['filtrer', 255]];
    }

    public function filtrer(MessageEvent $event): void
    {
        $envelope = $event->getEnvelope();
        $gardes = array_values(array_filter($envelope->getRecipients(), fn ($a) => !self::estDemo($a->getAddress())));
        if (!$gardes) {
            $event->reject();

            return;
        }
        $envelope->setRecipients($gardes);
    }

    public static function estDemo(string $email): bool
    {
        return str_ends_with(mb_strtolower(trim($email)), '@' . ClubDemoCommand::DOMAINE);
    }
}
