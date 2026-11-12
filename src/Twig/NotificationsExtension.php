<?php

namespace App\Twig;

use App\Service\NotificationsTelephone;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/** {{ notifications_cle() }} : clé publique des notifications sur le téléphone, null si elles ne sont pas réglées. */
class NotificationsExtension extends AbstractExtension
{
    public function __construct(private readonly NotificationsTelephone $notifications)
    {
    }

    public function getFunctions(): array
    {
        return [new TwigFunction('notifications_cle', fn () => $this->notifications->clePublique())];
    }
}
