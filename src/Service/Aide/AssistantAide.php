<?php

namespace App\Service\Aide;

use App\Entity\Utilisateur;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

/**
 * Assistant d'aide (fenêtre « ? » en bas à droite) : répond aux questions sur l'utilisation de l'application,
 * d'après le guide templates/aide/guide.md.
 *
 * Ce qui part au service d'IA : la question, les derniers échanges de la fenêtre, le type de compte (encadrement,
 * famille…) et la page ouverte. Jamais les données du club (joueurs, santé, finances…).
 */
class AssistantAide
{
    public const MAX_QUESTION = 1000;
    public const MAX_ECHANGES = 8;
    /** Questions par compte et par heure (protège le club d'une facture qui s'emballe). */
    public const MAX_PAR_HEURE = 40;

    private const CONSIGNES = <<<'TXT'
        Tu es l'assistant d'aide de l'application de gestion de club de football décrite dans le guide ci-dessous.
        Tu réponds aux membres du club (dirigeants, éducateurs, joueurs, parents) qui se demandent comment utiliser l'application.

        Comment répondre :
        - En français, simplement, pour des personnes qui ne sont pas à l'aise avec l'informatique. Pas de jargon technique.
        - Court : quelques phrases, ou des étapes numérotées (1., 2., 3.) quand il faut faire quelque chose. Nomme les menus et boutons tels qu'ils apparaissent.
        - Donne un exemple concret quand c'est utile (« par exemple, pour convoquer vos U15 samedi… »).
        - Tiens compte du type de compte et de la page indiqués avec la question : une famille n'a pas accès aux pages de l'encadrement.
        - Appuie-toi uniquement sur le guide. Si la réponse n'y est pas, dis-le honnêtement et conseille de demander au responsable du club ; n'invente pas de bouton ni de fonction.
        - Mise en forme : texte simple, **gras** pour les noms de menus ou de boutons, listes avec « - » ou « 1. ». Pas de titres, pas de tableaux, pas de liens.
        - Tu ne vois pas les données du club et tu ne peux rien modifier : explique comment faire, sans prétendre le faire.
        - Ne demande jamais d'informations personnelles (noms, santé, mots de passe…). Si on t'en donne, n'en tiens pas compte.
        - Pour une question sans rapport avec l'application ou le club, réponds poliment que tu es là pour aider à utiliser l'application.
        TXT;

    private ?string $guide = null;

    public function __construct(
        private readonly Repondeur $repondeur,
        private readonly CacheInterface $cache,
        #[Autowire('%env(ANTHROPIC_API_KEY)%')]
        private readonly string $cle,
        #[Autowire('%kernel.project_dir%/templates/aide/guide.md')]
        private readonly string $cheminGuide,
    ) {
    }

    /** Assistant proposé seulement si une clé d'API est réglée (ANTHROPIC_API_KEY dans .env.local). */
    public function estActif(): bool
    {
        return '' !== trim($this->cle);
    }

    /**
     * @param list<array{role?: mixed, content?: mixed}> $historique échanges précédents de la fenêtre (gardés côté navigateur)
     *
     * @throws ErreurAide
     */
    public function repondre(Utilisateur $utilisateur, string $question, array $historique, string $page): string
    {
        $question = trim(mb_substr($question, 0, self::MAX_QUESTION));
        if ('' === $question) {
            throw new ErreurAide('Écrivez votre question.');
        }
        $this->compter($utilisateur);

        // Derniers échanges seulement, rôles alternés, en commençant par une question.
        $messages = [];
        foreach (\array_slice($historique, -2 * self::MAX_ECHANGES) as $m) {
            $role = $m['role'] ?? null;
            $texte = \is_string($m['content'] ?? null) ? trim(mb_substr($m['content'], 0, 3000)) : '';
            if (!\in_array($role, ['user', 'assistant'], true) || '' === $texte || ($messages && end($messages)['role'] === $role) || (!$messages && 'assistant' === $role)) {
                continue;
            }
            $messages[] = ['role' => $role, 'content' => $texte];
        }
        if ($messages && 'user' === end($messages)['role']) {
            array_pop($messages);
        }
        $contexte = \sprintf("[Compte : %s. Page ouverte : %s]\n", $this->typeDeCompte($utilisateur), mb_substr(preg_replace('/[^\w\/\-?=&.]/u', '', $page) ?: '/', 0, 120));
        $messages[] = ['role' => 'user', 'content' => $contexte . $question];

        return $this->repondeur->repondre($this->guide(), self::CONSIGNES, $messages);
    }

    private function guide(): string
    {
        return $this->guide ??= (string) file_get_contents($this->cheminGuide);
    }

    private function typeDeCompte(Utilisateur $u): string
    {
        $roles = $u->getRoles();

        return match (true) {
            \in_array('ROLE_ADMIN', $roles, true) => 'administrateur du club',
            \in_array('ROLE_MEDICAL_SEUL', $roles, true) => 'personnel médical',
            $u->isFamille() => 'famille (joueur ou parent)',
            default => 'encadrement (éducateur ou dirigeant)',
        };
    }

    /** Limite par compte et par heure ; au-delà, message clair. */
    private function compter(Utilisateur $u): void
    {
        $cle = 'aide_' . $u->getId() . '_' . date('YmdH');
        $n = $this->cache->get($cle, function (ItemInterface $item): int {
            $item->expiresAfter(3700);

            return 0;
        });
        if ($n >= self::MAX_PAR_HEURE) {
            throw new ErreurAide(\sprintf('Vous avez posé %d questions cette heure-ci : l\'assistant reprend dans moins d\'une heure.', self::MAX_PAR_HEURE));
        }
        $this->cache->delete($cle);
        $this->cache->get($cle, function (ItemInterface $item) use ($n): int {
            $item->expiresAfter(3700);

            return $n + 1;
        });
    }
}
