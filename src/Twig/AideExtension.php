<?php

namespace App\Twig;

use App\Service\Aide\AssistantAide;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/** {{ aide_active() }} : la fenêtre d'aide « ? » s'affiche seulement si l'assistant est réglé (clé d'API). */
class AideExtension extends AbstractExtension
{
    public function __construct(private readonly AssistantAide $assistant)
    {
    }

    public function getFunctions(): array
    {
        return [new TwigFunction('aide_active', fn () => $this->assistant->estActif())];
    }
}
