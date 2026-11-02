<?php

namespace App\Service;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Planches anatomiques de l'infirmerie, reprises des formulaires Access « Sform Infirmerie … ».
 *
 * La planche « corps » renseigne la localisation et le membre puis ouvre une planche détaillée,
 * dont les zones renseignent la partie touchée (muscle, os, ligament). Positions en % du cadre.
 * Images dans public/images/anatomie.
 */
class Anatomie
{
    public function __construct(
        #[Autowire('%kernel.project_dir%/config/anatomie.json')]
        private readonly string $fichier,
    ) {
    }

    /**
     * @return array<string, array{titre: string, ratio: float, images: list<array{fichier: string, pos: list<float>}>, points: list<array<string, mixed>>, liens: list<array{texte: string, cible: string}>}>
     */
    public function planches(): array
    {
        return json_decode((string) file_get_contents($this->fichier), true, flags: \JSON_THROW_ON_ERROR);
    }
}
