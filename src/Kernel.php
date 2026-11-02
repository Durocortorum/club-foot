<?php

namespace App;

use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\HttpKernel\Kernel as BaseKernel;

class Kernel extends BaseKernel
{
    use MicroKernelTrait;

    /**
     * Un cache par version déployée : le fichier .version, écrit par outils/preparer-mise-a-jour.ps1,
     * change à chaque mise à jour, ce qui évite de devoir vider var/cache à la main sur l'hébergeur.
     */
    public function getCacheDir(): string
    {
        $fichier = $this->getProjectDir() . '/.version';
        $version = is_file($fichier) ? trim((string) file_get_contents($fichier)) : '';

        return parent::getCacheDir() . ('' !== $version ? '-' . $version : '');
    }
}
