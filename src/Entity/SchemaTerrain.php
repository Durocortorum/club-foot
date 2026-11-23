<?php

namespace App\Entity;

/**
 * Schéma dessiné sur un terrain (schema_controller.js) : exercices d'entraînement et tableaux tactiques.
 * Terrain, objets (joueurs, ballons, flèches, zones…) et étapes de l'animation.
 */
final class SchemaTerrain
{
    public const TERRAINS = ['entier' => 'Terrain entier', 'demi' => 'Demi-terrain', 'libre' => 'Zone libre'];
    public const MAX_ETAPES = 30;

    /**
     * Schéma reçu du navigateur, remis en ordre : terrain connu, objets sous forme de tableaux, et par étape la
     * nouvelle place [x, y] de chaque objet (null s'il ne bouge pas), avec ses points de passage s'il y en a
     * ([x, y, [[x, y], …]] : trajet courbé), alignée sur les objets.
     *
     * @param array<string, mixed> $schema
     *
     * @return array{terrain: string, objets: list<array<string, mixed>>, etapes?: list<list<array<int, mixed>|null>>}
     */
    public static function nettoyer(array $schema, string $terrainParDefaut = 'demi'): array
    {
        $objets = (array) ($schema['objets'] ?? []);
        $gardes = array_keys(array_filter($objets, 'is_array'));
        $propre = [
            'terrain' => isset(self::TERRAINS[$schema['terrain'] ?? null]) ? $schema['terrain'] : $terrainParDefaut,
            'objets' => array_values(array_filter($objets, 'is_array')),
        ];
        $point = fn ($p) => \is_array($p) && is_numeric($p[0] ?? null) && is_numeric($p[1] ?? null) ? [round((float) $p[0], 1), round((float) $p[1], 1)] : null;
        $etapes = [];
        foreach (\array_slice(array_values(array_filter((array) ($schema['etapes'] ?? []), 'is_array')), 0, self::MAX_ETAPES) as $etape) {
            $etapes[] = array_map(function ($i) use ($etape, $point) {
                $place = $point($etape[$i] ?? null);
                if (!$place) {
                    return null;
                }
                $passages = \is_array($etape[$i][2] ?? null) ? array_values(array_filter(array_map($point, \array_slice($etape[$i][2], 0, 20)))) : [];

                return $passages ? [...$place, $passages] : $place;
            }, $gardes);
        }
        if ($etapes) {
            $propre['etapes'] = $etapes;
        }

        return $propre;
    }
}
