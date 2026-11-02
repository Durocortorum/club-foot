<?php

namespace App\Service;

use App\Entity\Club;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Nom, logo et couleurs du club de l'utilisateur, disponibles dans tous les gabarits (variable Twig « apparence »).
 * Le logo envoyé est converti en PNG (512 px au plus, transparence conservée) dans var/club,
 * avec une icône carrée de 64 px pour l'onglet du navigateur.
 */
class Apparence
{
    private const TAILLE_LOGO = 512;
    private const TAILLE_ICONE = 64;

    public function __construct(
        private readonly ClubCourant $clubCourant,
        #[Autowire('%kernel.project_dir%/var/club')]
        private readonly string $dossier,
    ) {
    }

    /** Club de l'utilisateur connecté ; apparence neutre avant la connexion. */
    public function club(): Club
    {
        return $this->clubCourant->club() ?? new Club();
    }

    /** @param 'logo'|'icone' $variante */
    public function chemin(string $variante): ?string
    {
        $logo = $this->club()->getLogo();
        if (!$logo) {
            return null;
        }
        $chemin = $this->dossier . '/' . ('icone' === $variante ? 'icone-' . $logo : $logo);

        return is_file($chemin) ? $chemin : null;
    }

    /**
     * Convertit et enregistre un logo envoyé, supprime l'ancien et renvoie le nom du nouveau fichier.
     *
     * @throws \InvalidArgumentException si le fichier n'est pas une image lisible
     */
    public function enregistrerLogo(UploadedFile $fichier): string
    {
        $image = @imagecreatefromstring((string) file_get_contents($fichier->getPathname()));
        if (!$image) {
            throw new \InvalidArgumentException('Image illisible.');
        }
        if (!is_dir($this->dossier)) {
            mkdir($this->dossier, 0755, true);
        }
        $nom = 'logo-' . bin2hex(random_bytes(4)) . '.png';
        imagepng($this->redimensionner($image, self::TAILLE_LOGO, false), $this->dossier . '/' . $nom, 9);
        imagepng($this->redimensionner($image, self::TAILLE_ICONE, true), $this->dossier . '/icone-' . $nom, 9);
        $this->supprimerLogo($this->club()->getLogo());

        return $nom;
    }

    public function supprimerLogo(?string $nom): void
    {
        if ($nom && preg_match('/^logo-[0-9a-f]{8}\.png$/', $nom)) {
            @unlink($this->dossier . '/' . $nom);
            @unlink($this->dossier . '/icone-' . $nom);
        }
    }

    /** Réduit l'image dans un carré de $taille px (centrée sur fond transparent si $carre). */
    private function redimensionner(\GdImage $source, int $taille, bool $carre): \GdImage
    {
        [$l, $h] = [imagesx($source), imagesy($source)];
        $echelle = min(1, $taille / max($l, $h));
        [$nl, $nh] = [max(1, (int) round($l * $echelle)), max(1, (int) round($h * $echelle))];
        [$cl, $ch] = $carre ? [$taille, $taille] : [$nl, $nh];

        $cible = imagecreatetruecolor($cl, $ch);
        imagealphablending($cible, false);
        imagesavealpha($cible, true);
        imagefill($cible, 0, 0, imagecolorallocatealpha($cible, 0, 0, 0, 127));
        imagecopyresampled($cible, $source, intdiv($cl - $nl, 2), intdiv($ch - $nh, 2), 0, 0, $nl, $nh, $l, $h);

        return $cible;
    }
}
