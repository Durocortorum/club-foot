<?php

namespace App\Service;

use App\Entity\But;
use App\Entity\Rencontre;

/**
 * Compte rendu d'un match pour les familles : objet et texte proposés dans la messagerie, que l'éducateur
 * complète avant l'envoi. Score, mi-temps et buts seulement : le rapport interne et les notes ne sont pas repris.
 */
class CompteRenduMatch
{
    public const PREFIXE = 'Compte rendu';

    private const JOURS = ['dimanche', 'lundi', 'mardi', 'mercredi', 'jeudi', 'vendredi', 'samedi'];

    public function sujet(Rencontre $r): string
    {
        $score = null !== $r->getButsPour() ? \sprintf(' (%d-%d)', $r->getButsPour(), $r->getButsContre()) : '';

        return mb_substr(\sprintf('%s : %s contre %s%s', self::PREFIXE, $r->getEquipeAffichee(), $r->getAdversaire(), $score), 0, 120);
    }

    public function texte(Rencontre $r): string
    {
        $lignes = [];
        $quand = $r->getDate() ? self::JOURS[(int) $r->getDate()->format('w')] . ' ' . $r->getDate()->format('d/m') : null;
        $lignes[] = implode(' · ', array_filter([
            $r->getEquipeAffichee() . ' contre ' . $r->getAdversaire(),
            $quand,
            $r->getTournoi()?->getNom() ?? $r->getCompetition(),
            $r->isADomicile() ? 'à domicile' : 'à l\'extérieur',
        ]));
        if (null !== $r->getButsPour() && null !== $r->getButsContre()) {
            $lignes[] = '';
            $lignes[] = \sprintf('Score : %s %d – %d %s (%s).', $r->getDomicile(), $r->getButsDomicile(), $r->getButsExterieur(), $r->getExterieur(),
                match ($r->getResultat()) { 'V' => 'victoire', 'D' => 'défaite', default => 'match nul' });
            if (null !== $r->getButsDomicileMiTemps() && null !== $r->getButsExterieurMiTemps()) {
                $lignes[] = \sprintf('Mi-temps : %d – %d.', $r->getButsDomicileMiTemps(), $r->getButsExterieurMiTemps());
            }
        }
        $nos = array_values(array_filter($r->getButs()->toArray(), fn (But $b) => !$b->isAdverse()));
        if ($nos) {
            $lignes[] = '';
            $lignes[] = 'Nos buts :';
            foreach ($nos as $b) {
                $lignes[] = '– ' . trim(($b->getMinute() ? $b->getMinute() . ($b->getAdditionnel() ? '+' . $b->getAdditionnel() : '') . 'e min : ' : '')
                    . ($b->getButeur() ? $b->getButeur()->getPrenom() . ' ' . $b->getButeur()->getNom() : 'buteur non noté')
                    . ($b->getPasseur() ? ' (passe de ' . $b->getPasseur()->getPrenom() . ' ' . $b->getPasseur()->getNom() . ')' : ''));
            }
        }
        $lignes[] = '';
        $lignes[] = 'Le mot de l\'éducateur : ';
        $lignes[] = '';
        $lignes[] = 'Merci à tous les joueurs et aux parents présents.';

        return implode("\n", $lignes);
    }
}
