<?php

namespace App\Service\Aide;

use Anthropic\Client;
use Anthropic\Core\Exceptions\APIConnectionException;
use Anthropic\Core\Exceptions\APIStatusException;
use Anthropic\Core\Exceptions\AuthenticationException;
use Anthropic\Core\Exceptions\OverloadedException;
use Anthropic\Core\Exceptions\RateLimitException;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Réponse de l'assistant d'aide par Claude (API Anthropic, SDK PHP officiel).
 *
 * - Modèle : AIDE_MODELE (Claude Opus 5.5 par défaut), effort « low » : réponses rapides et courtes.
 * - Le guide et les consignes forment un préfixe stable mis en cache : seules la conversation et la question changent.
 * - Repli côté serveur en cas de refus (« fallbacks: default »).
 * - Aucune donnée des joueurs n'est envoyée : seulement la question, le rôle et la page où l'on se trouve.
 */
class RepondeurClaude implements Repondeur
{
    private ?Client $client = null;

    public function __construct(
        #[Autowire('%env(ANTHROPIC_API_KEY)%')]
        private readonly string $cle,
        #[Autowire('%env(AIDE_MODELE)%')]
        private readonly string $modele,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function repondre(string $guide, string $consignes, array $messages): string
    {
        $this->client ??= new Client(apiKey: $this->cle, requestOptions: ['timeout' => 60.0, 'maxRetries' => 2]);
        try {
            $reponse = $this->client->beta->messages->create(
                model: $this->modele,
                maxTokens: 16000,
                system: [
                    ['type' => 'text', 'text' => $consignes],
                    ['type' => 'text', 'text' => "<guide>\n" . $guide . "\n</guide>", 'cacheControl' => ['type' => 'ephemeral']],
                ],
                messages: $messages,
                outputConfig: ['effort' => 'low'],
                fallbacks: 'default',
                betas: ['server-side-fallback-2026-07-01'],
            );
        } catch (RateLimitException|OverloadedException) {
            throw new ErreurAide('L\'assistant est très demandé en ce moment : réessayez dans une minute.');
        } catch (AuthenticationException $e) {
            $this->logger->error('Assistant d\'aide : clé API refusée ({message}).', ['message' => $e->getMessage()]);
            throw new ErreurAide('L\'assistant n\'est pas disponible pour le moment.');
        } catch (APIStatusException|APIConnectionException $e) {
            $this->logger->error('Assistant d\'aide : erreur de l\'API ({message}).', ['message' => $e->getMessage()]);
            throw new ErreurAide('L\'assistant n\'a pas pu répondre. Réessayez dans un instant.');
        }

        if ('refusal' === $reponse->stopReason) {
            return 'Je ne peux pas répondre à cette question. Je suis là pour vous aider à utiliser l\'application du club.';
        }
        $texte = '';
        foreach ($reponse->content as $bloc) {
            if ('text' === $bloc->type) {
                $texte .= $bloc->text;
            }
        }
        $this->logger->info('Assistant d\'aide : {entree} jetons en entrée ({cache} lus en cache), {sortie} en sortie.', [
            'entree' => $reponse->usage->inputTokens,
            'cache' => $reponse->usage->cacheReadInputTokens,
            'sortie' => $reponse->usage->outputTokens,
        ]);

        return trim($texte) ?: 'Je n\'ai pas trouvé de réponse. Pouvez-vous reformuler votre question ?';
    }
}
