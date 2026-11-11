<?php

namespace App\Service;

use App\Entity\Classement;
use App\Entity\Rencontre;
use App\Entity\ResultatPoule;

/**
 * Recalcule le classement d'une poule : on part du dernier classement officiel (copié de la FFF)
 * et on y ajoute les matchs joués APRÈS le jour de cette mise à jour : ceux du club (ses rencontres
 * de championnat avec un score) et ceux des autres équipes, saisis à la main (ResultatPoule).
 * Victoire 3 points, nul 1, défaite 0 ; points ajustés à la main (pénalités) ; à égalité de points :
 * différence de buts, puis buts marqués. Le classement officiel suivant remet tout d'aplomb.
 */
class CalculClassement
{
    /**
     * @param list<ResultatPoule> $resultats
     * @param list<Rencontre>     $matchsClub rencontres de championnat de l'équipe pour la saison
     *
     * @return array{lignes: list<array<string, mixed>>, appliques: int, deja_comptes: int}|null null s'il n'y a rien à recalculer
     */
    public function calculer(Classement $classement, array $resultats, array $matchsClub): ?array
    {
        $base = $classement->getLignes();
        // Matchs déjà comptés dans l'officiel : ceux joués jusqu'au jour de sa mise à jour.
        $limite = $base ? $classement->getMisAJourLe()?->format('Y-m-d') : null;
        $apres = fn (\DateTimeInterface $d) => null === $limite || $d->format('Y-m-d') > $limite;

        $equipes = [];
        foreach ($base as $l) {
            $equipes[$l['equipe']] = array_intersect_key($l, array_flip(['equipe', 'points', 'joues', 'gagnes', 'nuls', 'perdus', 'pour', 'contre']));
        }
        $appliques = 0;
        $dejaComptes = 0;

        foreach ($resultats as $r) {
            if (!$r->isJoue()) {
                continue; // match à venir : pas encore de score
            }
            if (!$apres($r->getDate())) {
                ++$dejaComptes;
                continue;
            }
            $this->compter($equipes, $r->getDomicile(), $r->getExterieur(), $r->getButsDomicile(), $r->getButsExterieur());
            ++$appliques;
        }
        foreach ($matchsClub as $m) {
            if (null === $m->getResultat() || !$apres($m->getDate())) {
                continue;
            }
            $nous = $this->trouver($equipes, null) ?? mb_strtoupper((string) ($m->isADomicile() ? $m->getDomicile() : $m->getExterieur()));
            $eux = $this->trouver($equipes, $m->getAdversaire()) ?? mb_strtoupper((string) $m->getAdversaire());
            [$domicile, $exterieur] = $m->isADomicile() ? [$nous, $eux] : [$eux, $nous];
            $this->compter($equipes, $domicile, $exterieur, $m->getButsDomicile(), $m->getButsExterieur());
            ++$appliques;
        }

        $ajustements = $classement->getAjustements();
        if (0 === $appliques && !$ajustements) {
            return null;
        }
        foreach ($ajustements as $equipe => $points) {
            if (isset($equipes[$equipe])) {
                $equipes[$equipe]['points'] += $points;
            }
        }

        $lignes = array_values(array_map(fn (array $e) => $e + ['diff' => $e['pour'] - $e['contre'], 'ajustement' => $ajustements[$e['equipe']] ?? 0], $equipes));
        usort($lignes, fn (array $a, array $b) => [$b['points'], $b['diff'], $b['pour'], $a['equipe']] <=> [$a['points'], $a['diff'], $a['pour'], $b['equipe']]);
        foreach ($lignes as $i => &$l) {
            $l['rang'] = $i + 1;
        }
        unset($l);

        return ['lignes' => $lignes, 'appliques' => $appliques, 'deja_comptes' => $dejaComptes];
    }

    /**
     * Série en cours de chaque équipe : ses derniers matchs joués (du plus ancien au plus récent),
     * tirés du calendrier de la poule et des matchs du club. Clé : cœur du nom (Rencontre::coeurDuNom),
     * ou « #club » pour le club.
     *
     * @param list<ResultatPoule> $resultats
     * @param list<Rencontre>     $matchsClub
     *
     * @return array<string, list<array{resultat: string, titre: string}>>
     */
    public function series(array $resultats, array $matchsClub, int $nombre = 5): array
    {
        $matchs = [];
        foreach ($resultats as $r) {
            if ($r->isJoue()) {
                $matchs[] = [$r->getDate(), Rencontre::coeurDuNom($r->getDomicile()), Rencontre::coeurDuNom($r->getExterieur()), $r->getDomicile(), $r->getExterieur(), $r->getButsDomicile(), $r->getButsExterieur()];
            }
        }
        foreach ($matchsClub as $m) {
            if (null !== $m->getResultat()) {
                $eux = Rencontre::coeurDuNom($m->getAdversaire());
                $matchs[] = $m->isADomicile()
                    ? [$m->getDate(), '#club', $eux, $m->getDomicile(), $m->getExterieur(), $m->getButsDomicile(), $m->getButsExterieur()]
                    : [$m->getDate(), $eux, '#club', $m->getDomicile(), $m->getExterieur(), $m->getButsDomicile(), $m->getButsExterieur()];
            }
        }
        usort($matchs, fn (array $a, array $b) => $a[0] <=> $b[0]);

        $series = [];
        foreach ($matchs as [$date, $cleDom, $cleExt, $dom, $ext, $bd, $be]) {
            $titre = \sprintf('%s %s %d – %d %s', $date->format('d/m'), $dom, $bd, $be, $ext);
            $series[$cleDom][] = ['resultat' => $bd > $be ? 'V' : ($bd === $be ? 'N' : 'D'), 'titre' => $titre];
            $series[$cleExt][] = ['resultat' => $be > $bd ? 'V' : ($bd === $be ? 'N' : 'D'), 'titre' => $titre];
        }

        return array_map(fn (array $s) => \array_slice($s, -$nombre), $series);
    }

    /** Ajoute un match aux deux équipes (créées si elles ne sont pas encore au classement). */
    private function compter(array &$equipes, string $domicile, string $exterieur, int $bd, int $be): void
    {
        foreach ([[$domicile, $bd, $be], [$exterieur, $be, $bd]] as [$nom, $pour, $contre]) {
            $equipes[$nom] ??= ['equipe' => $nom, 'points' => 0, 'joues' => 0, 'gagnes' => 0, 'nuls' => 0, 'perdus' => 0, 'pour' => 0, 'contre' => 0];
            $e = &$equipes[$nom];
            ++$e['joues'];
            $e['pour'] += $pour;
            $e['contre'] += $contre;
            if ($pour > $contre) {
                ++$e['gagnes'];
                $e['points'] += 3;
            } elseif ($pour === $contre) {
                ++$e['nuls'];
                ++$e['points'];
            } else {
                ++$e['perdus'];
            }
            unset($e);
        }
    }

    /**
     * Nom d'une équipe du classement : le club (adversaire null) ou l'adversaire d'un match,
     * reconnu même écrit autrement (« FC », « U19 »… retirés, comme Classement::ligneDe).
     */
    private function trouver(array $equipes, ?string $adversaire): ?string
    {
        $cherche = null === $adversaire ? null : Rencontre::coeurDuNom($adversaire);
        foreach (array_keys($equipes) as $nom) {
            if (null === $cherche) {
                if (Rencontre::estLeClub($nom)) {
                    return $nom;
                }
                continue;
            }
            $coeur = Rencontre::coeurDuNom($nom);
            if ('' !== $cherche && ($coeur === $cherche || str_starts_with($coeur, $cherche . ' ') || str_starts_with($cherche, $coeur . ' '))) {
                return $nom;
            }
        }

        return null;
    }
}
