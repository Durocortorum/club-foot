<?php

namespace App\Service;

use App\Entity\Categorie;
use App\Entity\Exercice;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Exercices types proposés aux éducateurs, classés par secteur (config/exercices-types.json).
 * Un exercice type est copié dans une séance, où il peut ensuite être adapté.
 */
class BibliothequeExercices
{
    /** Groupes de la bibliothèque et secteurs du club qu'ils couvrent. */
    public const GROUPES = [
        'ecole_foot' => ['libelle' => 'École de foot', 'secteurs' => [Categorie::SECTEUR_ECOLE_FOOT]],
        'preformation' => ['libelle' => 'Préformation', 'secteurs' => [Categorie::SECTEUR_PREFORMATION]],
        'formation' => ['libelle' => 'Formation', 'secteurs' => [Categorie::SECTEUR_FORMATION]],
        'seniors' => ['libelle' => 'Seniors (Élite et Pro)', 'secteurs' => [Categorie::SECTEUR_ELITE, Categorie::SECTEUR_PRO]],
    ];

    public function __construct(
        #[Autowire('%kernel.project_dir%/config/exercices-types.json')]
        private readonly string $fichier,
    ) {
    }

    /** @return array<string, array{secteur: string, titre: string, duree: int, consignes: string, schema: array<string, mixed>}> */
    public function tous(): array
    {
        return json_decode((string) file_get_contents($this->fichier), true, flags: \JSON_THROW_ON_ERROR);
    }

    /**
     * Exercices par groupe, celui de la catégorie en premier.
     *
     * @return array<string, array{libelle: string, conseille: bool, exercices: array<string, array<string, mixed>>}>
     */
    public function parGroupe(?Categorie $categorie): array
    {
        $groupes = [];
        foreach (self::GROUPES as $cle => $groupe) {
            $groupes[$cle] = [
                'libelle' => $groupe['libelle'],
                'conseille' => \in_array($categorie?->getSecteur(), $groupe['secteurs'], true),
                'exercices' => [],
            ];
        }
        foreach ($this->tous() as $cle => $exercice) {
            $groupes[$exercice['secteur']]['exercices'][$cle] = $exercice;
        }
        uasort($groupes, fn (array $a, array $b) => $b['conseille'] <=> $a['conseille']);

        return $groupes;
    }

    public function creer(string $cle): ?Exercice
    {
        $type = $this->tous()[$cle] ?? null;

        return $type ? (new Exercice())
            ->setTitre($type['titre'])
            ->setDuree($type['duree'])
            ->setConsignes($type['consignes'])
            ->setSchema($type['schema']) : null;
    }
}
