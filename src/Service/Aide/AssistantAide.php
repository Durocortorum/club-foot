<?php

namespace App\Service\Aide;

use App\Entity\Utilisateur;

/**
 * Assistant d'aide (fenêtre « ? » en bas à droite) : répond aux questions sur l'utilisation de l'application avec
 * les passages du guide (templates/aide/guide.md) qui correspondent le mieux. Tout se passe sur le serveur du club :
 * aucun service extérieur, aucun coût, aucune donnée envoyée ailleurs.
 */
class AssistantAide
{
    public const MAX_QUESTION = 300;

    public function __construct(private readonly RechercheGuide $guide)
    {
    }

    /** Réponse en texte simple (gras « ** », listes « - » et « 1. »), affichée par aide_controller.js. */
    public function repondre(Utilisateur $utilisateur, string $question): string
    {
        $question = trim(mb_substr($question, 0, self::MAX_QUESTION));
        if ('' === $question) {
            throw new ErreurAide('Écrivez votre question.');
        }
        $passages = $this->guide->chercher($question, $utilisateur->isFamille());
        if (!$passages) {
            return "Je n'ai pas trouvé de réponse à cette question dans le guide. Essayez avec d'autres mots, par exemple "
                . "« convocation », « importer des joueurs », « cotisation » ou « notifications », ou demandez au responsable du club.\n\n"
                . "Sujets du guide :\n" . implode("\n", array_map(fn (string $r) => '- ' . $r, $this->guide->rubriques()));
        }

        return implode("\n\n", array_map(fn (array $p) => '**' . $p['rubrique'] . "**\n" . $p['texte'], $passages));
    }
}
