<?php

namespace App\Service;

use App\Entity\Blessure;
use App\Entity\But;
use App\Entity\Categorie;
use App\Entity\Convoque;
use App\Entity\Joueur;
use App\Entity\Participation;
use App\Entity\Rencontre;
use App\Entity\Sanction;
use App\Repository\ConvocationRepository;
use App\Repository\JoueurRepository;
use App\Repository\PresenceRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * « Composer le onze » d'un match : joueurs disponibles (convoqués, sinon l'effectif de l'équipe) avec leur note
 * moyenne à chaque poste sur la saison, leurs statistiques et leur disponibilité (blessure, suspension, absence à
 * la convocation) ; places et postes de chaque formation ; compositions des matchs précédents pour comparer.
 * Le onze validé est reporté sur la feuille de match (titulaires, postes, remplaçants, organisation, places).
 */
class CompositionOnze
{
    /** Place habituelle de chaque poste (comme CompositionTerrain), pour nommer les places d'une formation. */
    private const POSTES = [
        'Gardien' => [50, 6],
        'Latéral droit' => [86, 24], 'Latéral gauche' => [14, 24],
        'Défenseur central' => [50, 20], 'Défenseur central droit' => [63, 20], 'Défenseur central gauche' => [37, 20],
        'Milieu défensif axe' => [50, 38], 'Milieu défensif droit' => [65, 38], 'Milieu défensif gauche' => [35, 38],
        'Milieu relayeur' => [50, 50], 'Milieu relayeur droit' => [68, 50], 'Milieu relayeur gauche' => [32, 50],
        'Milieu offensif axe' => [50, 64], 'Milieu offensif droit' => [78, 64], 'Milieu offensif gauche' => [22, 64],
        'Milieu excentré droit' => [88, 55], 'Milieu excentré gauche' => [12, 55],
        'Attaquant' => [50, 82],
    ];

    /** Grandes familles de postes, pour proposer d'abord les joueurs de la bonne ligne. */
    public const LIGNES = ['Gardien' => 'G', 'Latéral' => 'D', 'Défenseur' => 'D', 'Milieu' => 'M', 'Attaquant' => 'A'];

    public function __construct(
        private readonly CompositionTerrain $terrain,
        private readonly ConvocationRepository $convocations,
        private readonly JoueurRepository $joueurs,
        private readonly EntityManagerInterface $em,
        private readonly UrlGeneratorInterface $routes,
        private readonly PresenceRepository $presences,
    ) {
    }

    /** Ligne du poste : « G », « D », « M », « A » (null si inconnue). */
    public static function ligne(?string $poste): ?string
    {
        foreach (self::LIGNES as $debut => $ligne) {
            if ($poste && str_starts_with($poste, $debut)) {
                return $ligne;
            }
        }

        return null;
    }

    /**
     * Places de chaque formation, avec le poste de chaque place (le plus proche de sa position).
     *
     * @return array<string, list<array{x: float, y: float, poste: string}>>
     */
    public function formations(): array
    {
        $formations = [];
        foreach (Rencontre::SYSTEMES as $systemes) {
            foreach ($systemes as $s) {
                $joueurs = str_contains($s, 'losange') ? 11 : array_sum(array_map('intval', str_split(str_replace(' ', '', $s)))) + 1;
                $formations[$s] = array_map(fn (array $p) => ['x' => $p[0], 'y' => $p[1], 'poste' => self::posteDe($p[0], $p[1])],
                    $this->terrain->placesDuSysteme($s, $joueurs));
            }
        }

        return $formations;
    }

    /** Poste dont la place habituelle est la plus proche (la profondeur compte plus que la largeur). */
    public static function posteDe(float $x, float $y): string
    {
        $meilleur = 'Milieu relayeur';
        $distance = \INF;
        foreach (self::POSTES as $poste => [$px, $py]) {
            $d = hypot($x - $px, ($y - $py) * 1.6);
            if ($d < $distance) {
                [$meilleur, $distance] = [$poste, $d];
            }
        }

        return $meilleur;
    }

    /**
     * Tout ce dont la page a besoin.
     *
     * @param bool $voitBlessures droit « Infirmerie » : motif et date de reprise ; sinon seulement « Indisponible »
     *
     * @return array<string, mixed>
     */
    public function donnees(Rencontre $rencontre, bool $voitBlessures): array
    {
        $date = $rencontre->getDate() ?? new \DateTimeImmutable('today');
        $saison = Rencontre::saisonDe($date);
        [$debut] = Rencontre::bornesSaison($saison);

        // Joueurs proposés : les convoqués (réponse comprise), sinon l'effectif de l'équipe ; ceux de la feuille en plus.
        $reponses = [];
        $candidats = [];
        if ($convocation = $this->convocations->pour($rencontre)) {
            foreach ($convocation->getConvoques() as $c) {
                $candidats[$c->getJoueur()->getId()] = $c->getJoueur();
                $reponses[$c->getJoueur()->getId()] = $c;
            }
        }
        $effectif = $rencontre->getCategorie() ? $this->joueurs->findBy(['categorie' => $rencontre->getCategorie(), 'ancien' => false], ['nom' => 'ASC', 'prenom' => 'ASC']) : [];
        $avecConvocation = (bool) $candidats;
        foreach ($rencontre->getParticipations() as $p) {
            if ($p->getJoueur()) {
                $candidats[$p->getJoueur()->getId()] = $p->getJoueur();
            }
        }
        // Toute l'équipe aussi (pour préparer convocation et tactique) ; avec une convocation, les autres joueurs
        // sont marqués « non convoqués » et « Meilleur onze » ne prend que les convoqués.
        foreach ($effectif as $j) {
            $candidats[$j->getId()] ??= $j;
        }
        $joueurs = array_values($candidats);

        $notes = $this->notesParPoste($joueurs, $debut, $date);
        $buts = $this->buts($joueurs, $debut, $date);
        $indispos = $this->indisponibilites($joueurs, $rencontre, $voitBlessures);
        $numeros = [];
        foreach ($rencontre->getParticipations() as $p) {
            if ($p->getJoueur() && null !== $p->getNumero()) {
                $numeros[$p->getJoueur()->getId()] = $p->getNumero();
            }
        }
        $numeros += $rencontre->getNumerosDirect();
        // Note et forme de la dernière semaine d'entraînement (grille des présences).
        $formes = $this->presences->formesAvant($joueurs, $date);

        $liste = [];
        foreach ($joueurs as $j) {
            $id = $j->getId();
            $n = $notes[$id] ?? ['postes' => [], 'matchs' => 0, 'minutes' => 0, 'note' => null];
            $c = $reponses[$id] ?? null;
            $etat = $indispos[$id] ?? null;
            if (!$etat && $c && Convoque::ABSENT === $c->getReponse()) {
                $etat = ['etat' => 'absent', 'libelle' => 'Absent à la convocation' . ($c->getMotif() ? ' : ' . $c->getMotif() : '')];
            }
            $liste[] = [
                'id' => $id,
                'nom' => (string) $j->getNom(),
                'prenom' => (string) $j->getPrenom(),
                'poste' => $j->getPoste(),
                'ligne' => self::ligne($j->getPoste()),
                'numero' => $numeros[$id] ?? null,
                'photo' => $j->getPhoto() ? $this->routes->generate('app_photo', ['nom' => str_replace('.jpg', '', $j->getPhoto())]) : null,
                'note' => $n['note'],
                'notesPostes' => $n['postes'],
                'matchs' => $n['matchs'],
                'minutes' => $n['minutes'],
                'buts' => $buts['buts'][$id] ?? 0,
                'passes' => $buts['passes'][$id] ?? 0,
                // Convoqué ou non (quand il y a une convocation), et sa réponse.
                'convoque' => !$avecConvocation || isset($reponses[$id]),
                'reponse' => $c?->getReponse(),
                'semaine' => $formes[$id] ?? null,
                'etat' => $etat['etat'] ?? 'dispo',
                'libelleEtat' => $etat['libelle'] ?? null,
            ];
        }

        return [
            'joueurs' => $liste,
            'avecConvocation' => $avecConvocation,
            'formations' => $this->formations(),
            'actuel' => $this->compositionActuelle($rencontre),
            'precedents' => $this->precedents($rencontre, $debut, $date),
            // Format de l'équipe (à 11, à 9, à 8…) : formation proposée par défaut.
            'joueursParEquipe' => Categorie::joueursParDefaut($rencontre->getCategorie()?->getNom()),
        ];
    }

    /**
     * Reporte le onze validé sur la feuille de match : titulaires (à leur poste et à leur place), remplaçants,
     * organisation de jeu. Les joueurs de la feuille qui ne sont plus retenus la quittent, sauf s'ils ont déjà
     * des données du match (temps de jeu, note, carton, but, remplacement) : ils restent alors sur le banc.
     *
     * @param list<array{joueur: int, x: float, y: float, poste: string}> $titulaires
     * @param list<int>                                                    $remplacants
     *
     * @return string|null message d'erreur, null si tout est reporté
     */
    public function valider(Rencontre $rencontre, string $systeme, array $titulaires, array $remplacants): ?string
    {
        $formations = $this->formations();
        if (!isset($formations[$systeme])) {
            return 'Formation inconnue.';
        }
        if (\count($titulaires) > \count($formations[$systeme])) {
            return \sprintf('Trop de titulaires pour un %s.', $systeme);
        }
        $ids = array_merge(array_column($titulaires, 'joueur'), $remplacants);
        if (\count($ids) !== \count(array_unique($ids))) {
            return 'Un joueur est choisi deux fois.';
        }
        $joueurs = [];
        foreach ($ids ? $this->joueurs->findBy(['id' => $ids]) : [] as $j) {
            $joueurs[$j->getId()] = $j;
        }
        if (\count($joueurs) !== \count($ids)) {
            return 'Joueur introuvable.';
        }

        // Joueurs qui ont déjà des données de ce match : on ne les retire pas de la feuille.
        $avecDonnees = [];
        foreach ($rencontre->getButs() as $b) {
            foreach ([$b->getButeur(), $b->getPasseur()] as $j) {
                if ($j) {
                    $avecDonnees[$j->getId()] = true;
                }
            }
        }
        foreach ($rencontre->getRemplacements() as $r) {
            foreach ([$r->getSortant(), $r->getEntrant()] as $j) {
                if ($j) {
                    $avecDonnees[$j->getId()] = true;
                }
            }
        }
        $choisis = array_flip($ids);
        foreach ($rencontre->getParticipations()->toArray() as $p) {
            $j = $p->getJoueur();
            if (!$j || isset($choisis[$j->getId()])) {
                continue;
            }
            if (isset($avecDonnees[$j->getId()]) || $p->getTempsJeu() > 0 || null !== $p->getNote() || $p->getCarton()) {
                $p->setTitulaire(false);
            } else {
                $rencontre->removeParticipation($p);
            }
        }

        $rencontre->setSysteme($systeme);
        $parJoueur = [];
        foreach ($rencontre->getParticipations() as $p) {
            if ($p->getJoueur()) {
                $parJoueur[$p->getJoueur()->getId()] = $p;
            }
        }
        $places = [];
        foreach ($titulaires as $t) {
            $p = $parJoueur[$t['joueur']] ?? $rencontre->ajouterAFeuille($joueurs[$t['joueur']]);
            $p->setTitulaire(true)->setPoste(\in_array($t['poste'], Participation::POSTES, true) ? $t['poste'] : self::posteDe($t['x'], $t['y']));
            $places[$t['joueur']] = [$t['x'], $t['y']];
        }
        foreach ($remplacants as $id) {
            $p = $parJoueur[$id] ?? $rencontre->ajouterAFeuille($joueurs[$id]);
            $p->setTitulaire(false);
            if ('Non utilisé' === $p->getPoste()) {
                $p->setPoste(null);
            }
        }
        // Places exactes du onze (schéma de composition, Match Live, tableau tactique).
        $rencontre->setPlacements($places);

        return null;
    }

    /**
     * Note moyenne de chaque joueur sur la saison jusqu'au match (tous postes, et par poste), matchs et minutes.
     *
     * @param list<Joueur> $joueurs
     *
     * @return array<int, array{postes: array<string, array{note: ?float, matchs: int}>, matchs: int, minutes: int, note: ?float}>
     */
    private function notesParPoste(array $joueurs, \DateTimeImmutable $debut, \DateTimeImmutable $avant): array
    {
        if (!$joueurs) {
            return [];
        }
        /** @var list<Participation> $participations */
        $participations = $this->em->createQueryBuilder()->select('p', 'r')->from(Participation::class, 'p')->join('p.rencontre', 'r')
            ->andWhere('p.joueur IN (:j)')->setParameter('j', $joueurs)
            ->andWhere('r.date >= :d AND r.date < :a')->setParameter('d', $debut)->setParameter('a', $avant)
            ->getQuery()->getResult();
        $cumul = [];
        foreach ($participations as $p) {
            if ($p->getRencontre()->isPlateau() || !($p->getTempsJeu() > 0)) {
                continue;
            }
            $id = $p->getJoueur()->getId();
            $cumul[$id] ??= ['matchs' => 0, 'minutes' => 0, 'notes' => [], 'postes' => []];
            ++$cumul[$id]['matchs'];
            $cumul[$id]['minutes'] += (int) $p->getTempsJeu();
            $poste = $p->getPoste();
            if ($poste && 'Non utilisé' !== $poste) {
                $cumul[$id]['postes'][$poste] ??= ['notes' => [], 'matchs' => 0];
                ++$cumul[$id]['postes'][$poste]['matchs'];
            }
            if (null !== $p->getNote()) {
                $cumul[$id]['notes'][] = (float) $p->getNote();
                if ($poste && 'Non utilisé' !== $poste) {
                    $cumul[$id]['postes'][$poste]['notes'][] = (float) $p->getNote();
                }
            }
        }
        $moyenne = fn (array $n) => $n ? round(array_sum($n) / \count($n), 1) : null;

        return array_map(fn (array $c) => [
            'matchs' => $c['matchs'],
            'minutes' => $c['minutes'],
            'note' => $moyenne($c['notes']),
            'postes' => array_map(fn (array $p) => ['note' => $moyenne($p['notes']), 'matchs' => $p['matchs']], $c['postes']),
        ], $cumul);
    }

    /**
     * Buts et passes décisives de la saison jusqu'au match.
     *
     * @param list<Joueur> $joueurs
     *
     * @return array{buts: array<int, int>, passes: array<int, int>}
     */
    private function buts(array $joueurs, \DateTimeImmutable $debut, \DateTimeImmutable $avant): array
    {
        $resultat = ['buts' => [], 'passes' => []];
        if (!$joueurs) {
            return $resultat;
        }
        foreach (['buts' => 'buteur', 'passes' => 'passeur'] as $cle => $role) {
            $lignes = $this->em->createQueryBuilder()->select("IDENTITY(b.$role) AS j, COUNT(b.id) AS n")->from(But::class, 'b')->join('b.rencontre', 'r')
                ->andWhere("b.$role IN (:j)")->setParameter('j', $joueurs)
                ->andWhere('r.date >= :d AND r.date < :a')->setParameter('d', $debut)->setParameter('a', $avant)
                ->andWhere('b.adverse = false')
                ->groupBy("b.$role")->getQuery()->getArrayResult();
            foreach ($lignes as $l) {
                $resultat[$cle][(int) $l['j']] = (int) $l['n'];
            }
        }

        return $resultat;
    }

    /**
     * Joueurs indisponibles le jour du match : blessure pas encore reprise en match, suspension en cours
     * (« 2 matchs de suspension » : comptée sur les matchs de l'équipe depuis la sanction), carton rouge au
     * match précédent de l'équipe.
     *
     * @param list<Joueur> $joueurs
     *
     * @return array<int, array{etat: string, libelle: string}>
     */
    private function indisponibilites(array $joueurs, Rencontre $rencontre, bool $voitBlessures): array
    {
        if (!$joueurs) {
            return [];
        }
        $date = $rencontre->getDate() ?? new \DateTimeImmutable('today');
        $etats = [];

        /** @var list<Blessure> $blessures */
        $blessures = $this->em->createQueryBuilder()->select('b')->from(Blessure::class, 'b')
            ->andWhere('b.joueur IN (:j)')->setParameter('j', $joueurs)
            ->andWhere('b.date <= :d')->setParameter('d', $date)
            ->getQuery()->getResult();
        foreach ($blessures as $b) {
            $reprise = $b->getRepriseMatchEffective();
            if (null !== $reprise && $reprise <= $date) {
                continue;
            }
            $etats[$b->getJoueur()->getId()] = ['etat' => 'blesse', 'libelle' => $voitBlessures
                ? 'Blessé' . ($b->getType() ? ' (' . mb_strtolower($b->getType()) . ')' : '') . ($reprise ? ', reprise des matchs le ' . $reprise->format('d/m') : ', reprise non fixée')
                : 'Indisponible'];
        }

        // Suspensions : « N match(s) de suspension » depuis la date de la sanction.
        /** @var list<Sanction> $sanctions */
        $sanctions = $this->em->createQueryBuilder()->select('s')->from(Sanction::class, 's')
            ->andWhere('s.joueur IN (:j)')->setParameter('j', $joueurs)
            ->andWhere('s.date <= :d')->setParameter('d', $date)
            ->andWhere('s.date >= :depuis')->setParameter('depuis', $date->modify('-6 months'))
            ->getQuery()->getResult();
        $matchsEquipe = $rencontre->getCategorie() ? $this->em->createQueryBuilder()->select('r')->from(Rencontre::class, 'r')
            ->andWhere('r.categorie = :c')->setParameter('c', $rencontre->getCategorie())
            ->andWhere('r.date < :d')->setParameter('d', $date)
            ->andWhere('r.date >= :depuis')->setParameter('depuis', $date->modify('-6 months'))
            ->orderBy('r.date', 'DESC')->getQuery()->getResult() : [];
        $matchsEquipe = array_values(array_filter($matchsEquipe, fn (Rencontre $r) => !$r->isPlateau()));
        foreach ($sanctions as $s) {
            if (!preg_match('/(\d+)\s*matchs?\b.*suspen|suspen\D*(\d+)\s*match/iu', (string) $s->getSanctionSportive(), $m)) {
                continue;
            }
            $nombre = (int) ($m[1] ?: $m[2]);
            $purges = \count(array_filter($matchsEquipe, fn (Rencontre $r) => $r->getDate() > $s->getDate()));
            if ($purges < $nombre && !isset($etats[$s->getJoueur()->getId()])) {
                $reste = $nombre - $purges;
                $etats[$s->getJoueur()->getId()] = ['etat' => 'suspendu', 'libelle' => \sprintf('Suspendu : encore %d match%s (sanction du %s)', $reste, $reste > 1 ? 's' : '', $s->getDate()->format('d/m'))];
            }
        }

        // Carton rouge au dernier match de l'équipe : suspension probable (à vérifier).
        if ($precedent = $matchsEquipe[0] ?? null) {
            foreach ($precedent->getParticipations() as $p) {
                if ($p->getJoueur() && \in_array($p->getCarton(), Participation::CARTONS_ROUGES, true) && !isset($etats[$p->getJoueur()->getId()])) {
                    $etats[$p->getJoueur()->getId()] = ['etat' => 'suspendu', 'libelle' => 'Carton rouge au dernier match (' . $precedent->getDate()->format('d/m') . ') : suspension probable'];
                }
            }
        }

        return $etats;
    }

    /**
     * Composition déjà sur la feuille : titulaires à leur place (comme le schéma de composition), remplaçants.
     *
     * @return array{systeme: ?string, titulaires: list<array{joueur: int, x: float, y: float}>, remplacants: list<int>}
     */
    private function compositionActuelle(Rencontre $rencontre): array
    {
        $compo = $this->terrain->placer($rencontre);

        return [
            'systeme' => $rencontre->isPlacementEducateur() ? null : $rencontre->getSysteme(),
            'titulaires' => array_map(fn (array $t) => ['joueur' => $t['participation']->getJoueur()->getId(), 'x' => $t['x'], 'y' => $t['y']], $compo['titulaires']),
            'remplacants' => array_values(array_map(fn (Participation $p) => $p->getJoueur()->getId(),
                array_filter($compo['remplacants'], fn (Participation $p) => 'Non utilisé' !== $p->getPoste()))),
        ];
    }

    /**
     * Compositions des matchs joués par l'équipe cette saison avant ce match (les plus récents d'abord) :
     * organisation, titulaires, score et résultat.
     *
     * @return list<array<string, mixed>>
     */
    private function precedents(Rencontre $rencontre, \DateTimeImmutable $debut, \DateTimeImmutable $avant): array
    {
        if (!$rencontre->getCategorie()) {
            return [];
        }
        /** @var list<Rencontre> $matchs */
        $matchs = $this->em->createQueryBuilder()->select('r', 'p')->from(Rencontre::class, 'r')->join('r.participations', 'p')
            ->andWhere('r.categorie = :c')->setParameter('c', $rencontre->getCategorie())
            ->andWhere('r.date >= :d AND r.date < :a')->setParameter('d', $debut)->setParameter('a', $avant)
            ->andWhere('r.id != :id')->setParameter('id', $rencontre->getId())
            ->orderBy('r.date', 'DESC')->getQuery()->getResult();
        $liste = [];
        foreach ($matchs as $r) {
            $titulaires = array_values(array_map(fn (Participation $p) => $p->getJoueur()->getId(),
                array_filter($r->getParticipations()->toArray(), fn (Participation $p) => $p->getJoueur() && $p->isTitulaire())));
            if ($r->isPlateau() || !$titulaires) {
                continue;
            }
            $liste[] = [
                'id' => $r->getId(),
                'date' => $r->getDate()->format('d/m'),
                'adversaire' => $r->getAdversaire(),
                'domicile' => $r->isADomicile(),
                'systeme' => $r->isPlacementEducateur() ? 'Placement libre' : ($r->getSysteme() ?: '—'),
                'score' => null !== $r->getButsPour() ? $r->getButsPour() . ' – ' . $r->getButsContre() : null,
                'resultat' => $r->getResultat(),
                'titulaires' => $titulaires,
            ];
        }

        return $liste;
    }
}
