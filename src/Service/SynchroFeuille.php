<?php

namespace App\Service;

use App\Entity\ActionMatch;
use App\Entity\But;
use App\Entity\Convoque;
use App\Entity\Joueur;
use App\Entity\Participation;
use App\Entity\Remplacement;
use App\Entity\Rencontre;
use App\Repository\ActionMatchRepository;
use App\Repository\ConvocationRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Match en direct et feuille de match tenus à jour l'un par l'autre, pour les buts, les cartons de nos joueurs
 * et les changements :
 * - versFeuille() : ce qui vient d'être noté, corrigé ou supprimé sur une tablette l'est aussi sur la feuille
 *   (score et temps de jeu recalculés) ;
 * - versDirect() : ce qui vient d'être saisi, corrigé ou supprimé sur la feuille l'est aussi dans le direct
 *   (les tablettes le reçoivent à leur prochain échange).
 * Seul ce qui a changé est repris : le reste de la feuille et du direct n'est jamais touché.
 * Un but du direct et un but de la feuille sont « le même but » : même camp, même buteur, minute à 5 près.
 * Un changement : même sortant, même entrant, même minute. Les cartons : par joueur.
 * À appeler avant le flush.
 */
class SynchroFeuille
{
    private const BUTS = ['B', 'BA'];
    private const CARTONS = ['CJ', 'CR', ActionMatch::EXCLUSION_TEMPORAIRE];

    /** Joueurs ajoutés à la feuille par la dernière mise à jour (prénom nom). @var list<string> */
    private array $ajoutes = [];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ActionMatchRepository $actions,
        private readonly ConvocationRepository $convocations,
        private readonly MatchDirect $direct,
    ) {
    }

    /** Photo d'une action (avant sa modification) : de quoi retrouver son équivalent sur la feuille. */
    public static function photoAction(ActionMatch $a): array
    {
        return ['type' => $a->getType(), 'joueur' => $a->getJoueur()?->getId(), 'minute' => $a->getMinute(), 'details' => $a->getDetails()];
    }

    public static function concerne(?string $type): bool
    {
        return \in_array($type, [...self::BUTS, ...self::CARTONS, ActionMatch::CHANGEMENT], true);
    }

    /** @return list<string> joueurs ajoutés à la feuille par la dernière mise à jour */
    public function ajoutes(): array
    {
        return $this->ajoutes;
    }

    // ———————— Direct → feuille ————————

    /**
     * @param list<array{0: ?array, 1: ?ActionMatch}> $changements [photo avant (null : nouvelle), action après (null : supprimée)]
     *
     * @return array{buts: int, cartons: int, changements: int} éléments de la feuille ajoutés ou modifiés
     */
    public function versFeuille(Rencontre $rencontre, array $changements): array
    {
        $this->ajoutes = [];
        $compte = ['buts' => 0, 'cartons' => 0, 'changements' => 0];
        $changements = array_values(array_filter($changements, fn (array $c) => self::concerne($c[0]['type'] ?? null) || self::concerne($c[1]?->getType())));
        if (!$changements) {
            return $compte;
        }
        $this->preremplir($rencontre);
        $butsChanges = false;
        $joueursCartons = [];
        $toutes = $this->actions->duMatch($rencontre);
        foreach ($changements as [$avant, $apres]) {
            $typeAvant = $avant['type'] ?? null;
            $typeApres = $apres?->getType();

            // Buts.
            if (\in_array($typeAvant, self::BUTS, true) || \in_array($typeApres, self::BUTS, true)) {
                $butsChanges = true;
                $but = \in_array($typeAvant, self::BUTS, true) ? $this->butDeLaFeuille($rencontre, $typeAvant, $avant['joueur'], $avant['minute']) : null;
                if (!$but && \in_array($typeApres, self::BUTS, true)) {
                    $but = $this->butDeLaFeuille($rencontre, $typeApres, $apres->getJoueur()?->getId(), $apres->getMinute());
                }
                if (\in_array($typeApres, self::BUTS, true)) {
                    $etat = $but ? self::etatBut($but) : null;
                    if (!$but) {
                        $rencontre->addBut($but = new But());
                    }
                    $this->remplirBut($rencontre, $but, $apres);
                    // Comparaison des valeurs (105 relu de la base vaut 105.0).
                    $compte['buts'] += $etat == self::etatBut($but) ? 0 : 1;
                } elseif ($but) {
                    $rencontre->removeBut($but);
                    ++$compte['buts'];
                }
            }

            // Cartons : celui de la feuille est recalculé pour chaque joueur concerné.
            foreach ([[$typeAvant, $avant['joueur'] ?? null], [$typeApres, $apres?->getJoueur()?->getId()]] as [$type, $joueurId]) {
                if (\in_array($type, self::CARTONS, true) && $joueurId) {
                    $joueursCartons[$joueurId] = true;
                }
            }

            // Changements.
            if (ActionMatch::CHANGEMENT === $typeAvant || ActionMatch::CHANGEMENT === $typeApres) {
                $r = ActionMatch::CHANGEMENT === $typeAvant ? $this->remplacementDeLaFeuille($rencontre, $avant['joueur'], $avant['details']['entrant'] ?? null, $avant['minute']) : null;
                if (!$r && ActionMatch::CHANGEMENT === $typeApres) {
                    $r = $this->remplacementDeLaFeuille($rencontre, $apres->getJoueur()?->getId(), $apres->getDetails()['entrant'] ?? null, $apres->getMinute());
                }
                if (ActionMatch::CHANGEMENT === $typeApres && $apres->getJoueur()) {
                    $etat = $r ? [$r->getSortant()?->getId(), $r->getEntrant()?->getId(), $r->getMinute(), $r->getMotif()] : null;
                    if (!$r) {
                        $rencontre->addRemplacement($r = new Remplacement());
                    }
                    $entrant = isset($apres->getDetails()['entrant']) ? $this->em->find(Joueur::class, $apres->getDetails()['entrant']) : null;
                    $r->setSortant($apres->getJoueur())->setEntrant($entrant)->setMinute($apres->getMinute())->setMotif($apres->getDetails()['motif'] ?? 'Choix');
                    $this->surFeuille($rencontre, $apres->getJoueur());
                    if ($entrant) {
                        $this->surFeuille($rencontre, $entrant);
                    }
                    $compte['changements'] += $etat === [$r->getSortant()?->getId(), $r->getEntrant()?->getId(), $r->getMinute(), $r->getMotif()] ? 0 : 1;
                } elseif ($r) {
                    $rencontre->removeRemplacement($r);
                    ++$compte['changements'];
                }
            }
        }

        foreach (array_keys($joueursCartons) as $joueurId) {
            $liste = array_values(array_filter($toutes, fn (ActionMatch $a) => \in_array($a->getType(), self::CARTONS, true) && $a->getJoueur()?->getId() === $joueurId));
            [$code, $minute] = self::cartonDeFeuille($liste);
            $p = $code ? $this->surFeuille($rencontre, $this->em->getReference(Joueur::class, $joueurId)) : $this->participation($rencontre, $joueurId);
            if ($p && ($p->getCarton() !== $code || $p->getMinuteCarton() !== $minute)) {
                $p->setCarton($code)->setMinuteCarton($minute);
                ++$compte['cartons'];
            }
        }

        if ($butsChanges) {
            $this->recalculerScore($rencontre);
        }
        // Joueur entré par un changement noté en direct : pas titulaire.
        $rencontre->decocherTitulairesEntres();
        $rencontre->calculerTempsJeu();

        return $compte;
    }

    /**
     * Ce qui a été noté en direct et manque sur la feuille (noté avant la mise à jour automatique) :
     * buts sans but correspondant, cartons différents, changements absents des remplacements.
     *
     * @return array{buts: int, cartons: int, changements: int}
     */
    public function aReporter(Rencontre $rencontre): array
    {
        $compte = ['buts' => 0, 'cartons' => 0, 'changements' => 0];
        if (!$rencontre->getId()) {
            return $compte;
        }
        $actions = $this->actions->duMatch($rencontre);
        $butsFeuille = $rencontre->getButs()->toArray();
        $cartons = [];
        foreach ($actions as $a) {
            $type = $a->getType();
            if (\in_array($type, self::BUTS, true)) {
                $trouve = self::retrouver($butsFeuille, fn (But $b) => ($b->isAdverse() ? 'BA' : 'B') === $type
                    && self::buteur($b)?->getId() === ('BA' === $type ? null : $a->getJoueur()?->getId()) && abs(self::minuteDuBut($b) - $a->getMinute()) <= 5);
                $compte['buts'] += $trouve ? 0 : 1;
            } elseif (\in_array($type, self::CARTONS, true) && $a->getJoueur()) {
                $cartons[$a->getJoueur()->getId()][] = $a;
            } elseif (ActionMatch::CHANGEMENT === $type && $a->getJoueur()) {
                $compte['changements'] += $this->remplacementDeLaFeuille($rencontre, $a->getJoueur()->getId(), $a->getDetails()['entrant'] ?? null, $a->getMinute()) ? 0 : 1;
            }
        }
        foreach ($cartons as $joueurId => $liste) {
            $p = $this->participation($rencontre, $joueurId);
            $compte['cartons'] += $p?->getCarton() === self::cartonDeFeuille($liste)[0] ? 0 : 1;
        }

        return $compte;
    }

    /** Toutes les actions du direct reportées sur la feuille (rattrapage : bouton « Reporter sur la feuille de match »). */
    public function toutVersFeuille(Rencontre $rencontre): array
    {
        return $this->versFeuille($rencontre, array_map(fn (ActionMatch $a) => [null, $a], $this->actions->duMatch($rencontre)));
    }

    /** Carton de la feuille d'après les cartons du direct d'un joueur : rouge, puis carton blanc, puis jaune. */
    public static function cartonDeFeuille(array $liste): array
    {
        usort($liste, fn (ActionMatch $a, ActionMatch $b) => [$a->getPeriode(), $a->getMinute()] <=> [$b->getPeriode(), $b->getMinute()]);
        $jaunes = array_values(array_filter($liste, fn (ActionMatch $a) => 'CJ' === $a->getType()));
        $rouge = array_values(array_filter($liste, fn (ActionMatch $a) => 'CR' === $a->getType()))[0] ?? null;
        $blanc = array_values(array_filter($liste, fn (ActionMatch $a) => ActionMatch::EXCLUSION_TEMPORAIRE === $a->getType()))[0] ?? null;
        [$code, $minute] = match (true) {
            \count($jaunes) >= 2 => ['J+R', $jaunes[1]->getMinute()],
            null !== $rouge && $jaunes => ['J+R', $rouge->getMinute()],
            null !== $rouge => ['RJ', $rouge->getMinute()],
            null !== $blanc => ['BL', $blanc->getMinute()],
            (bool) $jaunes => ['JJ', $jaunes[0]->getMinute()],
            default => [null, null],
        };

        return [$code, null === $minute ? null : (string) $minute];
    }

    private function remplirBut(Rencontre $rencontre, But $but, ActionMatch $a): void
    {
        $mi = intdiv($this->direct->duree($rencontre), 2);
        $fin = 1 === $a->getPeriode() ? $mi : 2 * $mi;
        $d = $a->getDetails() ?? [];
        $but->setAdverse('BA' === $a->getType())->setMinute(min($a->getMinute(), $fin))
            ->setAdditionnel($a->getMinute() > $fin ? min(20, $a->getMinute() - $fin) : null);
        if (isset($d['depart'], $d['arrivee'])) {
            $but->setTrace($d);
        }
        // Corner, coup franc, penalty : « Situation » de la feuille et phase « Coup de pied arrêté ». Une situation de la
        // feuille qui dit déjà la même chose est gardée (« Corner droit » plutôt que « Corner »).
        if ('csc' !== ($d['geste'] ?? null)) {
            $situation = $d['situation'] ?? null;
            $avant = ActionMatch::situationDeConditions($but->getConditions());
            if (null !== $situation && $avant !== $situation) {
                $but->setConditions(ActionMatch::conditionsDeSituation($situation, $d['depart'] ?? null, 'BA' === $a->getType()));
            } elseif (null === $situation && null !== $avant) {
                $but->setConditions(null);
            }
            if (null !== $situation && null === $but->getPhase()) {
                $but->setPhase('CPA');
            } elseif (null === $situation && 'CPA' === $but->getPhase()) {
                $but->setPhase(null);
            }
        }
        if ('BA' === $a->getType()) {
            $but->setButeur(null)->setPasseur(null);

            return;
        }
        // Contre son camp d'un adversaire : pas de buteur ; tête notée en direct.
        $csc = 'csc' === ($d['geste'] ?? null);
        $but->setButeur($csc ? null : $a->getJoueur())->setConditions($csc ? 'CSC' : ('CSC' === $but->getConditions() ? null : $but->getConditions()));
        if ('tete' === ($d['geste'] ?? null)) {
            $but->setGeste('Tête');
        } elseif ('Tête' === $but->getGeste()) {
            $but->setGeste(null);
        }
        $passeur = isset($d['passeur']) ? $this->em->find(Joueur::class, $d['passeur']) : null;
        $but->setPasseur($passeur);
        foreach ([$but->getButeur(), $passeur] as $j) {
            if ($j) {
                $this->surFeuille($rencontre, $j);
            }
        }
    }

    /** Ce qui compte dans un but de la feuille : a-t-il changé ? */
    private static function etatBut(But $b): array
    {
        return [$b->isAdverse(), $b->getButeur()?->getId(), $b->getPasseur()?->getId(), $b->getMinute(), $b->getAdditionnel(), $b->getGeste(), $b->getConditions(), $b->getTrace()];
    }

    /** Score de la feuille d'après ses buts (comme la feuille : mi-temps = buts jusqu'à la moitié de la durée). */
    public function recalculerScore(Rencontre $rencontre): void
    {
        $mi = intdiv($this->direct->duree($rencontre), 2);
        $pour = $contre = $pourMt = $contreMt = 0;
        foreach ($rencontre->getButs() as $b) {
            $premiere = null !== $b->getMinute() && $b->getMinute() <= $mi;
            if ($b->isAdverse()) {
                ++$contre;
                $contreMt += $premiere ? 1 : 0;
            } else {
                ++$pour;
                $pourMt += $premiere ? 1 : 0;
            }
        }
        $dom = $rencontre->isADomicile();
        $rencontre->setButsDomicile($dom ? $pour : $contre)->setButsExterieur($dom ? $contre : $pour)
            ->setButsDomicileMiTemps($dom ? $pourMt : $contreMt)->setButsExterieurMiTemps($dom ? $contreMt : $pourMt);
    }

    // ———————— Feuille → direct ————————

    /** Photo de ce que la feuille partage avec le direct (avant le formulaire). */
    public static function photoFeuille(Rencontre $rencontre): array
    {
        $photo = ['buts' => [], 'cartons' => [], 'remplacements' => []];
        foreach ($rencontre->getButs() as $b) {
            $photo['buts'][self::cle($b)] = [
                'type' => $b->isAdverse() ? 'BA' : 'B', 'buteur' => self::buteur($b)?->getId(), 'minute' => self::minuteDuBut($b),
                'passeur' => $b->isAdverse() ? null : $b->getPasseur()?->getId(), 'geste' => $b->getGeste(), 'conditions' => $b->getConditions(), 'trace' => $b->getTrace(),
            ];
        }
        foreach ($rencontre->getParticipations() as $p) {
            if ($p->getJoueur()) {
                $photo['cartons'][$p->getJoueur()->getId()] = [$p->getCarton(), $p->getMinuteCarton()];
            }
        }
        foreach ($rencontre->getRemplacements() as $r) {
            $photo['remplacements'][self::cle($r)] = [$r->getSortant()?->getId(), $r->getEntrant()?->getId(), $r->getMinute(), $r->getMotif()];
        }
        ksort($photo['cartons']);

        return $photo;
    }

    /** Empreinte de la photo : la feuille ouverte dans un formulaire a-t-elle changé depuis (par une tablette) ? */
    public static function empreinte(Rencontre $rencontre): string
    {
        $photo = self::photoFeuille($rencontre);
        $photo['cartons'] = array_filter($photo['cartons'], fn (array $c) => null !== $c[0]);

        return md5(json_encode([array_values($photo['buts']), $photo['cartons'], array_values($photo['remplacements'])]));
    }

    /** Match suivi en direct : au moins une action notée. */
    public function suiviEnDirect(Rencontre $rencontre): bool
    {
        return null !== $rencontre->getId() && null !== $this->actions->findOneBy(['rencontre' => $rencontre]);
    }

    /** Ce qui a changé sur la feuille depuis la photo, fait aussi dans le direct (match suivi en direct seulement). */
    public function versDirect(Rencontre $rencontre, array $avant): void
    {
        if (!$this->suiviEnDirect($rencontre)) {
            return;
        }
        $apres = self::photoFeuille($rencontre);
        $notees = $this->actions->duMatch($rencontre);
        $mi = intdiv($this->direct->duree($rencontre), 2);

        // Buts.
        $butsDirect = array_values(array_filter($notees, fn (ActionMatch $a) => \in_array($a->getType(), self::BUTS, true)));
        foreach ($avant['buts'] as $cle => $b) {
            if (($apres['buts'][$cle] ?? null) === $b) {
                continue; // inchangé
            }
            $action = self::retrouver($butsDirect, fn (ActionMatch $a) => $a->getType() === $b['type'] && $a->getJoueur()?->getId() === $b['buteur'] && abs($a->getMinute() - $b['minute']) <= 5);
            if (isset($apres['buts'][$cle])) {
                $this->remplirActionBut($rencontre, $action, $apres['buts'][$cle], $mi);
            } elseif ($action) {
                $this->em->remove($action);
            }
        }
        foreach (array_diff_key($apres['buts'], $avant['buts']) as $b) {
            $action = self::retrouver($butsDirect, fn (ActionMatch $a) => $a->getType() === $b['type'] && $a->getJoueur()?->getId() === $b['buteur'] && abs($a->getMinute() - $b['minute']) <= 5);
            $this->remplirActionBut($rencontre, $action, $b, $mi);
        }

        // Cartons de nos joueurs : remplacés par ceux de la feuille quand ils ont changé.
        foreach (array_unique([...array_keys($avant['cartons']), ...array_keys($apres['cartons'])]) as $joueurId) {
            $nouveau = $apres['cartons'][$joueurId] ?? [null, null];
            if (($avant['cartons'][$joueurId] ?? [null, null]) === $nouveau) {
                continue;
            }
            $anciens = array_values(array_filter($notees, fn (ActionMatch $a) => \in_array($a->getType(), self::CARTONS, true) && $a->getJoueur()?->getId() === $joueurId));
            $minute = (int) floor(Rencontre::ordreMinute($nouveau[1]) ?? 0);
            $types = match ($nouveau[0]) {
                'J+R' => ['CJ', 'CR'],
                'RJ', 'RC' => ['CR'],
                'BL' => [ActionMatch::EXCLUSION_TEMPORAIRE],
                'JJ', 'JC' => ['CJ'],
                default => [],
            };
            foreach ($types as $type) {
                // Une action du même type est gardée (avec sa zone) ; sinon une nouvelle, hors carte.
                $action = self::retrouver($anciens, fn (ActionMatch $a) => $a->getType() === $type);
                if (!$action) {
                    $action = $this->nouvelleAction($rencontre, $type, 2, 1, ['horsCarte' => true]);
                }
                $action->setJoueur($this->em->getReference(Joueur::class, $joueurId))
                    ->placer($minute > $mi ? 2 : 1, $minute, $action->getZx(), $action->getZy());
            }
            foreach ($anciens as $a) {
                $this->em->remove($a);
            }
        }

        // Remplacements.
        $changementsDirect = array_values(array_filter($notees, fn (ActionMatch $a) => ActionMatch::CHANGEMENT === $a->getType()));
        $meme = fn (array $r) => fn (ActionMatch $a) => $a->getJoueur()?->getId() === $r[0] && ($a->getDetails()['entrant'] ?? null) === $r[1] && $a->getMinute() === ($r[2] ?? 0);
        foreach ($avant['remplacements'] as $cle => $r) {
            if (($apres['remplacements'][$cle] ?? null) === $r) {
                continue;
            }
            $action = self::retrouver($changementsDirect, $meme($r));
            if (isset($apres['remplacements'][$cle])) {
                $this->remplirActionChangement($rencontre, $action, $apres['remplacements'][$cle], $mi);
            } elseif ($action) {
                $this->em->remove($action);
            }
        }
        foreach (array_diff_key($apres['remplacements'], $avant['remplacements']) as $r) {
            $this->remplirActionChangement($rencontre, self::retrouver($changementsDirect, $meme($r)), $r, $mi);
        }
    }

    private function remplirActionBut(Rencontre $rencontre, ?ActionMatch $action, array $b, int $mi): void
    {
        $depart = $b['trace']['depart'] ?? null;
        if (!$action) {
            // Nouveau but dans le direct : placé d'après la feuille, sinon hors carte.
            $action = $this->nouvelleAction($rencontre, $b['type'], 0, 1, null);
        }
        $ancien = $action->getDetails() ?? [];
        $zx = $depart ? (int) floor(min(104.9, max(0, $depart[0])) / 17.5) : $action->getZx();
        $zy = $depart ? (int) floor(min(67.9, max(0, $depart[1])) / (68 / 3)) : $action->getZy();
        // Sans place connue : hors carte pour un but créé ici ; un but du direct garde sa zone.
        $horsCarte = !$depart && !isset($ancien['depart']) && (null === $action->getId() || $action->isHorsCarte());
        $action->setType($b['type'])->setJoueur($b['buteur'] ? $this->em->getReference(Joueur::class, $b['buteur']) : null)
            ->placer($b['minute'] > $mi ? 2 : 1, $b['minute'], $zx, $zy)
            ->setDetails(array_filter([
                'depart' => $depart ?? $ancien['depart'] ?? null,
                'arrivee' => $b['trace']['arrivee'] ?? $ancien['arrivee'] ?? null,
                'geste' => 'CSC' === $b['conditions'] ? 'csc' : ('Tête' === $b['geste'] ? 'tete' : (($ancien['geste'] ?? null) === 'tete' ? 'tir' : ($ancien['geste'] ?? null))),
                'passeur' => $b['passeur'],
                'situation' => ActionMatch::situationDeConditions($b['conditions']),
                'coupFranc' => $ancien['coupFranc'] ?? null,
                'horsCarte' => $horsCarte ?: null,
            ], fn ($v) => null !== $v) ?: null);
    }

    private function remplirActionChangement(Rencontre $rencontre, ?ActionMatch $action, array $r, int $mi): void
    {
        if (!$r[0]) {
            if ($action) {
                $this->em->remove($action);
            }

            return;
        }
        $action ??= $this->nouvelleAction($rencontre, ActionMatch::CHANGEMENT, 0, 1, null);
        $minute = $r[2] ?? 0;
        $action->setJoueur($this->em->getReference(Joueur::class, $r[0]))->placer($minute > $mi ? 2 : 1, $minute, 0, 1)
            ->setDetails(['entrant' => $r[1], 'motif' => $r[3] ?? 'Choix']);
    }

    private function nouvelleAction(Rencontre $rencontre, string $type, int $zx, int $zy, ?array $details): ActionMatch
    {
        $o = random_bytes(16);
        $o[6] = \chr(\ord($o[6]) & 0x0F | 0x40);
        $o[8] = \chr(\ord($o[8]) & 0x3F | 0x80);
        $action = (new ActionMatch($rencontre, vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($o), 4))))->setType($type);
        $action->placer(1, 0, $zx, $zy)->setDetails($details);
        $this->em->persist($action);

        return $action;
    }

    // ———————— Outils ————————

    /** Clé d'un but ou d'un remplacement : son numéro, ou « nouveau » pour une ligne pas encore enregistrée. */
    private static function cle(But|Remplacement $x): string
    {
        return null !== $x->getId() ? 'id' . $x->getId() : 'nouveau' . spl_object_id($x);
    }

    /** Premier élément qui convient, retiré de la liste (chacun ne sert qu'une fois). */
    private static function retrouver(array &$liste, callable $convient): ?object
    {
        foreach ($liste as $i => $x) {
            if ($convient($x)) {
                unset($liste[$i]);

                return $x;
            }
        }

        return null;
    }

    private function butDeLaFeuille(Rencontre $rencontre, string $type, ?int $joueurId, int $minute): ?But
    {
        // Contre son camp : pas de buteur, des deux côtés.
        foreach ($rencontre->getButs() as $b) {
            if (($b->isAdverse() ? 'BA' : 'B') === $type && self::buteur($b)?->getId() === ($type === 'BA' ? null : $joueurId) && abs(self::minuteDuBut($b) - $minute) <= 5) {
                return $b;
            }
        }

        return null;
    }

    private function remplacementDeLaFeuille(Rencontre $rencontre, ?int $sortant, ?int $entrant, int $minute): ?Remplacement
    {
        foreach ($rencontre->getRemplacements() as $r) {
            if ($r->getSortant()?->getId() === $sortant && $r->getEntrant()?->getId() === $entrant && ($r->getMinute() ?? 0) === $minute) {
                return $r;
            }
        }

        return null;
    }

    private static function buteur(But $b): ?Joueur
    {
        return $b->isAdverse() || 'CSC' === $b->getConditions() ? null : $b->getButeur();
    }

    public static function minuteDuBut(But $b): int
    {
        return (int) ($b->getMinute() ?? 0) + (int) ($b->getAdditionnel() ?? 0);
    }

    private function participation(Rencontre $rencontre, int $joueurId): ?Participation
    {
        foreach ($rencontre->getParticipations() as $p) {
            if ($p->getJoueur()?->getId() === $joueurId) {
                return $p;
            }
        }

        return null;
    }

    /** Le joueur sur la feuille (ajouté s'il n'y est pas, avec le numéro donné sur la tablette). */
    private function surFeuille(Rencontre $rencontre, Joueur $joueur): Participation
    {
        if ($p = $this->participation($rencontre, (int) $joueur->getId())) {
            return $p;
        }
        // Numéro (tablette, sinon premier libre) et titulaire (tablette, sinon numéro ; jamais au-delà du terrain).
        $p = $rencontre->ajouterAFeuille($joueur);
        $this->ajoutes[] = trim($joueur->getPrenom() . ' ' . $joueur->getNom());

        return $p;
    }

    /** Feuille encore vide : d'abord les joueurs présents à la convocation (comme la feuille de match le fait). */
    private function preremplir(Rencontre $rencontre): void
    {
        if ($rencontre->getParticipations()->isEmpty() && ($convocation = $this->convocations->pour($rencontre))) {
            foreach ($convocation->parReponse(Convoque::PRESENT) as $c) {
                $rencontre->ajouterAFeuille($c->getJoueur());
            }
        }
    }
}
