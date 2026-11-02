<?php

namespace App\Service;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Enregistre les photos des fiches (joueurs, encadrement, détections) en JPEG
 * redimensionné, dans var/photos. Ce dossier n'est pas public : les photos,
 * souvent de mineurs, ne sont servies qu'aux utilisateurs connectés (PhotoController).
 */
class Photos
{
    private const LARGEUR_MAX = 400;

    public function __construct(
        #[Autowire('%kernel.project_dir%/var/photos')]
        private readonly string $dossier,
    ) {
    }

    /**
     * Enregistre une photo envoyée depuis un formulaire et renvoie son nom de fichier.
     * L'ancienne photo de la fiche est supprimée.
     */
    public function enregistrer(UploadedFile $fichier, string $prefixe, ?string $ancienne = null): string
    {
        $nom = sprintf('%s-%s.jpg', $prefixe, bin2hex(random_bytes(4)));
        $this->ecrire((string) file_get_contents($fichier->getPathname()), $nom);
        $this->supprimer($ancienne);

        return $nom;
    }

    /**
     * Convertit une photo stockée dans un champ « Objet OLE » d'Access.
     * Ce sont des images Paint (BMP) précédées d'un en-tête OLE ; certaines,
     * collées depuis un autre logiciel, sont un métafichier contenant le même BMP.
     */
    public function depuisOle(string $ole, string $nom): void
    {
        for ($debut = strpos($ole, 'BM', 20); false !== $debut; $debut = strpos($ole, 'BM', $debut + 2)) {
            $taille = unpack('V', substr($ole, $debut + 2, 4))[1];
            $entete = unpack('V', substr($ole, $debut + 14, 4))[1];
            // Un vrai BMP : en-tête DIB de 40 octets ou plus, taille cohérente avec l'objet.
            if (\in_array($entete, [40, 108, 124], true) && $taille > 54 && $debut + $taille <= \strlen($ole) + 4) {
                $this->ecrire(substr($ole, $debut, $taille), $nom);

                return;
            }
        }

        // Métafichier : l'image y est un bitmap brut (DIB) sans en-tête de fichier BMP.
        foreach ($this->bitmapsBruts($ole) as $bmp) {
            try {
                $this->ecrire($bmp, $nom);

                return;
            } catch (\RuntimeException) {
            }
        }

        throw new \RuntimeException('Aucune image BMP trouvée dans l\'objet OLE.');
    }

    /**
     * Repère les en-têtes DIB plausibles (24 bits d'abord) et les complète en fichiers BMP.
     *
     * @return list<string>
     */
    private function bitmapsBruts(string $ole): array
    {
        $candidats = [];
        for ($p = strpos($ole, pack('V', 40)); false !== $p; $p = strpos($ole, pack('V', 40), $p + 1)) {
            ['largeur' => $largeur, 'hauteur' => $hauteur, 'plans' => $plans, 'bits' => $bits, 'compression' => $compression]
                = unpack('llargeur/lhauteur/vplans/vbits/Vcompression', substr($ole, $p + 4, 16));
            if (1 !== $plans || !\in_array($bits, [24, 32], true) || 0 !== $compression || $largeur < 20 || $largeur > 5000 || abs($hauteur) < 20 || abs($hauteur) > 5000) {
                continue;
            }
            $donnees = (int) ceil($bits * $largeur / 32) * 4 * abs($hauteur);
            if ($p + 40 + $donnees > \strlen($ole)) {
                continue;
            }
            $entete = 'BM' . pack('V', 54 + $donnees) . pack('V', 0) . pack('V', 54);
            $candidats[$bits][] = $entete . substr($ole, $p, 40 + $donnees);
        }

        return array_merge($candidats[24] ?? [], $candidats[32] ?? []);
    }

    public function chemin(string $nom): ?string
    {
        $chemin = $this->dossier . '/' . basename($nom);

        return is_file($chemin) ? $chemin : null;
    }

    public function existe(string $nom): bool
    {
        return null !== $this->chemin($nom);
    }

    public function supprimer(?string $nom): void
    {
        if ($nom && $chemin = $this->chemin($nom)) {
            unlink($chemin);
        }
    }

    private function ecrire(string $contenu, string $nom): void
    {
        $image = @imagecreatefromstring($contenu);
        if (!$image) {
            throw new \RuntimeException('Format d\'image non reconnu.');
        }
        if (imagesx($image) > self::LARGEUR_MAX) {
            $image = imagescale($image, self::LARGEUR_MAX);
        }
        if (!is_dir($this->dossier)) {
            mkdir($this->dossier, 0775, true);
        }
        imagejpeg($image, $this->dossier . '/' . $nom, 85);
    }
}
