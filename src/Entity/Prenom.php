<?php

namespace App\Entity;

/**
 * Écriture des prénoms : « JEAN-PIERRE » ou « jean-pierre » deviennent « Jean-Pierre ».
 * Un prénom déjà en casse mixte (« DeShawn ») est gardé tel qu'il a été saisi.
 */
final class Prenom
{
    public static function formater(?string $prenom): ?string
    {
        if (null === $prenom) {
            return null;
        }
        $prenom = trim(preg_replace('/\s+/u', ' ', $prenom));
        if ($prenom !== mb_strtoupper($prenom) && $prenom !== mb_strtolower($prenom)) {
            return $prenom;
        }

        // Majuscule après un espace, un trait d'union ou une apostrophe.
        return preg_replace_callback(
            '/(^|[\s\'’-])(\p{Ll})/u',
            fn (array $m) => $m[1] . mb_strtoupper($m[2]),
            mb_strtolower($prenom),
        );
    }
}
