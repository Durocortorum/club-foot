<?php

namespace App\Service;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Chiffre les secrets confiés par les clubs (secret de l'API HelloAsso…) avant de les enregistrer en base :
 * AES-256-GCM, clé dérivée de APP_SECRET. Si APP_SECRET change, les secrets ne se lisent plus et le club
 * doit les ressaisir (rien d'autre n'est perdu).
 */
class Coffre
{
    private const ALGO = 'aes-256-gcm';

    public function __construct(
        #[Autowire('%kernel.secret%')]
        private readonly string $secret,
    ) {
    }

    public function chiffrer(string $texte): string
    {
        $iv = random_bytes(12);
        $tag = '';
        $chiffre = openssl_encrypt($texte, self::ALGO, $this->cle(), \OPENSSL_RAW_DATA, $iv, $tag);
        if (false === $chiffre) {
            throw new \RuntimeException('Chiffrement impossible.');
        }

        return base64_encode($iv . $tag . $chiffre);
    }

    /** @return string|null null si le secret ne se lit plus (APP_SECRET changé, donnée abîmée) */
    public function dechiffrer(?string $donnee): ?string
    {
        $brut = $donnee ? base64_decode($donnee, true) : false;
        if (false === $brut || \strlen($brut) < 29) {
            return null;
        }
        $texte = openssl_decrypt(substr($brut, 28), self::ALGO, $this->cle(), \OPENSSL_RAW_DATA, substr($brut, 0, 12), substr($brut, 12, 16));

        return false === $texte ? null : $texte;
    }

    private function cle(): string
    {
        return hash_hmac('sha256', 'coffre-club-foot', $this->secret, true);
    }
}
