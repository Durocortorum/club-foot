<?php

namespace App\Service;

use App\Entity\Categorie;
use App\Entity\Entrainement;
use App\Entity\Joueur;
use App\Entity\Rencontre;
use App\Entity\Ressenti;
use App\Repository\EntrainementRepository;
use App\Repository\JoueurRepository;
use App\Repository\RencontreRepository;
use App\Repository\RessentiRepository;

/**
 * Autoévaluation (RPE) : séances que le joueur peut noter, et tableau de la semaine pour l'éducateur
 * (notes de chaque joueur, charge de la semaine comparée à la moyenne des quatre précédentes).
 */
class SuiviRessenti
{
    /** Charge de la semaine au-delà de laquelle elle est signalée : 1,5 fois la moyenne des 4 précédentes. */
    public const SEUIL_HAUSSE = 1.5;

    public function __construct(
        private readonly EntrainementRepository $entrainements,
        private readonly RencontreRepository $rencontres,
        private readonly RessentiRepository $ressentis,
        private readonly JoueurRepository $joueurs,
    ) {
    }

    /**
     * Séances des derniers jours que le joueur n'a pas encore notées : entraînements de son équipe
     * (sauf annulés ou absence signalée) et matchs de son équipe, jusqu'à aujourd'hui.
     *
     * @param list<Ressenti> $deja ressentis déjà saisis
     *
     * @return list<Entrainement|Rencontre>
     */
    public function aNoter(Joueur $joueur, array $deja): array
    {
        if (!$c = $joueur->getCategorie()) {
            return [];
        }
        [$debut, $fin] = [new \DateTimeImmutable('today -' . Ressenti::DELAI_JOURS . ' days'), new \DateTimeImmutable('today')];
        $notes = [];
        foreach ($deja as $r) {
            $notes[$r->getEntrainement() ? 'e' . $r->getEntrainement()->getId() : 'r' . $r->getRencontre()?->getId()] = true;
        }
        $seances = [];
        foreach ($this->entrainements->entre([$c], $debut, $fin) as $e) {
            if (!$e->isAnnule() && !$e->isSupprime() && !$e->absenceDe($joueur) && !isset($notes['e' . $e->getId()])) {
                $seances[] = $e;
            }
        }
        foreach ($this->rencontres->findBy(['categorie' => $c]) as $m) {
            if ($m->getDate() >= $debut && $m->getDate() <= $fin && !isset($notes['r' . $m->getId()])) {
                $seances[] = $m;
            }
        }
        usort($seances, fn ($a, $b) => $b->getDate() <=> $a->getDate());

        return $seances;
    }

    /**
     * Tableau d'une semaine (lundi → dimanche) pour une équipe : colonnes = séances, lignes = joueurs.
     *
     * @return array{seances: list<array{cle: string, objet: Entrainement|Rencontre, moyenne: ?float, reponses: int}>, lignes: list<array{joueur: Joueur, notes: array<string, Ressenti>, charge: int, moyenne4: ?float, ratio: ?float, alerte: bool}>, reponses: int}
     */
    public function semaine(Categorie $categorie, \DateTimeImmutable $lundi): array
    {
        $dimanche = $lundi->modify('+6 days');
        $seances = [];
        foreach ($this->entrainements->entre([$categorie], $lundi, $dimanche) as $e) {
            if (!$e->isAnnule() && !$e->isSupprime()) {
                $seances['e' . $e->getId()] = $e;
            }
        }
        foreach ($this->rencontres->findBy(['categorie' => $categorie]) as $m) {
            if ($m->getDate() >= $lundi && $m->getDate() <= $dimanche) {
                $seances['r' . $m->getId()] = $m;
            }
        }
        uasort($seances, fn ($a, $b) => $a->getDate() <=> $b->getDate());

        // Cette semaine et les quatre précédentes (moyenne de référence).
        $parJoueur = [];
        foreach ($this->ressentis->deLEquipe($categorie, $lundi->modify('-28 days'), $dimanche) as $r) {
            $parJoueur[$r->getJoueur()->getId()][] = $r;
        }
        $lignes = [];
        $reponses = 0;
        foreach ($this->joueurs->findBy(['categorie' => $categorie, 'ancien' => false], ['nom' => 'ASC', 'prenom' => 'ASC']) as $j) {
            $notes = [];
            $charge = 0;
            $avant = [0, 0, 0, 0];
            foreach ($parJoueur[$j->getId()] ?? [] as $r) {
                if ($r->getDate() >= $lundi) {
                    $notes[$r->getEntrainement() ? 'e' . $r->getEntrainement()->getId() : 'r' . $r->getRencontre()->getId()] = $r;
                    $charge += $r->getCharge();
                    ++$reponses;
                } else {
                    $avant[intdiv((int) $r->getDate()->diff($lundi)->days - 1, 7)] += $r->getCharge();
                }
            }
            // Moyenne des semaines précédentes où il a noté quelque chose.
            $semainesNotees = array_filter($avant);
            $moyenne4 = $semainesNotees ? array_sum($semainesNotees) / \count($semainesNotees) : null;
            $ratio = $moyenne4 && $charge ? $charge / $moyenne4 : null;
            $lignes[] = ['joueur' => $j, 'notes' => $notes, 'charge' => $charge, 'moyenne4' => $moyenne4, 'ratio' => $ratio, 'alerte' => null !== $ratio && $ratio >= self::SEUIL_HAUSSE];
        }
        $colonnes = [];
        foreach ($seances as $cle => $s) {
            $valeurs = array_filter(array_map(fn (array $l) => $l['notes'][$cle] ?? null, $lignes));
            $colonnes[] = [
                'cle' => $cle,
                'objet' => $s,
                'reponses' => \count($valeurs),
                'moyenne' => $valeurs ? array_sum(array_map(fn (Ressenti $r) => $r->getRpe(), $valeurs)) / \count($valeurs) : null,
            ];
        }

        return ['seances' => $colonnes, 'lignes' => $lignes, 'reponses' => $reponses];
    }
}
