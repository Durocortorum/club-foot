<?php

namespace App\Service\Aide;

/**
 * Produit la réponse de l'assistant d'aide. En production : Claude (RepondeurClaude) ; dans les tests : une réponse
 * fixe, sans appel payant.
 */
interface Repondeur
{
    /**
     * @param string                                                   $guide       guide de l'application (stable : mis en cache)
     * @param string                                                   $consignes   règles de l'assistant (stables)
     * @param list<array{role: 'user'|'assistant', content: string}> $messages    conversation, dernière question comprise
     *
     * @throws ErreurAide si le service ne répond pas
     */
    public function repondre(string $guide, string $consignes, array $messages): string;
}
