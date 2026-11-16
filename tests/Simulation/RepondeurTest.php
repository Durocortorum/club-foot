<?php

namespace App\Tests\Simulation;

use App\Service\Aide\Repondeur;

/** Assistant d'aide dans les tests : garde ce qui lui est envoyé et répond une phrase fixe, sans appeler l'API. */
class RepondeurTest implements Repondeur
{
    /** @var list<array{guide: string, consignes: string, messages: list<array{role: string, content: string}>}> */
    public static array $appels = [];

    public function repondre(string $guide, string $consignes, array $messages): string
    {
        self::$appels[] = ['guide' => $guide, 'consignes' => $consignes, 'messages' => $messages];

        return 'Pour convoquer, ouvrez **Matchs**, puis le match, puis **Convocation**.';
    }
}
