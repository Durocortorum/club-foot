<?php

namespace App\Twig;

use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Accord d'un mot avec un nombre, à la française : singulier pour 0 et 1, pluriel à partir de 2.
 * {{ accord(n, 'match joué', 'matchs joués') }} ; sans pluriel donné, on ajoute un « s ».
 */
class TexteExtension extends AbstractExtension
{
    public function getFunctions(): array
    {
        return [new TwigFunction('accord', self::accord(...))];
    }

    public static function accord(int|float|null $nombre, string $singulier, ?string $pluriel = null): string
    {
        return abs((float) $nombre) >= 2 ? ($pluriel ?? $singulier . 's') : $singulier;
    }
}
