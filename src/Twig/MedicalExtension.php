<?php

namespace App\Twig;

use App\Service\AccesMedical;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/** Dans les gabarits : medical_ouvert() (droit + code valable) et medical_jusqu_a() (heure de fermeture). */
class MedicalExtension extends AbstractExtension
{
    public function __construct(private readonly AccesMedical $acces)
    {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('medical_ouvert', $this->acces->estOuvert(...)),
            new TwigFunction('medical_jusqu_a', fn () => $this->acces->estOuvert() ? $this->acces->ouvertJusquA() : null),
        ];
    }
}
