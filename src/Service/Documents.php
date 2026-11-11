<?php

namespace App\Service;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Documents joints (bulletins scolaires, contrats, justificatifs), rangés dans var/documents,
 * hors du dossier public : ils ne sont servis qu'après contrôle des droits.
 */
class Documents
{
    public function __construct(
        #[Autowire('%kernel.project_dir%/var/documents')]
        private readonly string $dossier,
    ) {
    }

    public function enregistrer(UploadedFile $fichier, string $prefixe, ?string $ancien = null): string
    {
        // PDF, ou photo (justificatifs de dépenses) : l'extension suit le contenu réel du fichier.
        $extension = \in_array($e = $fichier->guessExtension(), ['jpg', 'jpeg', 'png'], true) ? $e : 'pdf';
        $nom = sprintf('%s-%s.%s', $prefixe, bin2hex(random_bytes(6)), $extension);
        $fichier->move($this->dossier, $nom);
        $this->supprimer($ancien);

        return $nom;
    }

    public function chemin(string $nom): ?string
    {
        $chemin = $this->dossier . '/' . basename($nom);

        return is_file($chemin) ? $chemin : null;
    }

    public function supprimer(?string $nom): void
    {
        if ($nom && $chemin = $this->chemin($nom)) {
            unlink($chemin);
        }
    }
}
