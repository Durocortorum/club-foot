<?php

namespace App\Controller;

use App\Entity\ActionMatch;
use App\Entity\Joueur;
use App\Entity\Rencontre;
use App\Repository\ActionMatchRepository;
use App\Security\ActiviteVoter;
use App\Service\CompositionTerrain;
use App\Service\MatchDirect;
use App\Service\SynchroFeuille;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Table de statistiques du match, en direct sur la tablette, le téléphone ou l'ordinateur : joueur, action, zone
 * en trois touches, minute prise au chrono. Les actions partent au serveur dès que le réseau le permet (elles
 * restent sur l'appareil en attendant) ; on corrige ou supprime une action depuis le journal. Puis le bilan :
 * actions par joueur, carte du terrain, quarts d'heure, et les buts reportés sur la feuille de match.
 */
#[IsGranted(ActiviteVoter::GERER, 'rencontre')]
class MatchDirectController extends AbstractController
{
    public function __construct(
        private readonly ActionMatchRepository $actions,
        private readonly MatchDirect $direct,
        private readonly EntityManagerInterface $em,
        private readonly SynchroFeuille $synchro,
    ) {
    }

    #[Route('/matchs/{id}/direct', name: 'app_match_direct', requirements: ['id' => '\d+'], methods: ['GET'])]
    #[IsGranted(ActiviteVoter::MODIFIER_MATCH, 'rencontre')]
    public function direct(Rencontre $rencontre): Response
    {
        return $this->render('match_direct/direct.html.twig', [
            'rencontre' => $rencontre,
            'config' => $this->config($rencontre),
            'meteos' => Rencontre::METEOS,
            'terrains' => Rencontre::TERRAINS,
            'vents' => Rencontre::VENTS,
            'temperatures' => array_map('strval', range(-15, 40)),
        ]);
    }

    /**
     * « Match Live » : version simple du direct, pour le téléphone (gros score, boutons But, Carton, Remplacement,
     * Mi-temps, Fin ; événements, statistiques, composition).
     * Mêmes données que la tablette détaillée (même chrono, mêmes actions, même enregistrement).
     */
    #[Route('/matchs/{id}/live', name: 'app_match_live', requirements: ['id' => '\d+'], methods: ['GET'])]
    #[IsGranted(ActiviteVoter::MODIFIER_MATCH, 'rencontre')]
    public function live(Rencontre $rencontre, CompositionTerrain $terrain): Response
    {
        $club = $rencontre->getClub();
        // Composition sur le terrain : places des titulaires de la feuille de match (placés par l'éducateur, sinon
        // d'après le système et les postes) ; places du système pour les titulaires choisis sur la tablette.
        $places = [];
        foreach ($terrain->placer($rencontre)['titulaires'] as $t) {
            $places[$t['participation']->getJoueur()->getId()] = [$t['x'], $t['y']];
        }
        $systeme = $rencontre->isPlacementEducateur() ? null : $rencontre->getSysteme();

        return $this->render('match_direct/live.html.twig', [
            'rencontre' => $rencontre,
            'config' => $this->config($rencontre) + [
                'equipe' => (string) $rencontre->getCategorie(),
                'competition' => $rencontre->isPlateau() ? 'Plateau' : $rencontre->getCompetition(),
                'date' => $rencontre->getDate()?->format('Y-m-d'),
                'heure' => $rencontre->getHeure(),
                'lieu' => $rencontre->getLieu(),
                'domicile' => $rencontre->isADomicile(),
                'logo' => $club?->getLogo() ? $this->generateUrl('app_club_logo', ['version' => $club->getVersion()]) : null,
                'places' => (object) $places,
                'placesSysteme' => $terrain->placesDuSysteme($systeme, $rencontre->joueursSurLeTerrain()),
            ],
        ]);
    }

    /** Ce dont la page du direct a besoin (tablette détaillée ou Match Live). */
    private function config(Rencontre $rencontre): array
    {
        $actions = $this->actions->duMatch($rencontre);

        return [
            'match' => $rencontre->getId(),
            'joueurs' => $this->direct->joueurs($rencontre, $actions),
            'actions' => array_map(fn (ActionMatch $a) => $a->enTableau(), $actions),
            'types' => array_map(fn (array $a) => $a[0], ActionMatch::ACTIONS),
            'adverses' => ActionMatch::ADVERSES,
            'duree' => $this->direct->duree($rencontre),
            'url' => $this->generateUrl('app_match_direct_enregistrer', ['id' => $rencontre->getId()]),
            'jeton' => $this->container->get('security.csrf.token_manager')->getToken('direct' . $rencontre->getId())->getValue(),
            'nous' => $rencontre->isADomicile() ? $rencontre->getDomicile() : $rencontre->getExterieur(),
            'adversaire' => $rencontre->getAdversaire(),
            'groupes' => ActionMatch::GROUPES,
            'moi' => $this->moi()->getId(),
            'chrono' => $rencontre->getChronoDirect(),
            'titulaires' => $rencontre->titulairesPourLeDirect(),
            'maxTitulaires' => $rencontre->joueursSurLeTerrain(),
            'maintenant' => self::maintenant(),
            // Match de coupe : ce qui suit une égalité à la fin (tirs au but, prolongations, nul ; null : à choisir).
            'coupe' => $rencontre->isCoupe(),
            'egalite' => $rencontre->getEgalite(),
            'egalites' => Rencontre::EGALITES,
        ];
    }

    /**
     * Échange avec chaque tablette, toutes les quelques secondes : elle envoie ses actions nouvelles ou corrigées
     * (même uuid : remplacée), ses suppressions, son chrono s'il a changé, la météo et le terrain ; elle reçoit toutes
     * les actions du match (celles des autres tablettes comprises), le chrono commun et l'heure du serveur.
     * Une action notée par un autre compte n'est ni corrigée ni supprimée.
     */
    #[Route('/matchs/{id}/direct/enregistrer', name: 'app_match_direct_enregistrer', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[IsGranted(ActiviteVoter::MODIFIER_MATCH, 'rencontre')]
    public function enregistrer(Request $request, Rencontre $rencontre): JsonResponse
    {
        $donnees = json_decode($request->getContent(), true);
        if (!\is_array($donnees) || !$this->isCsrfTokenValid('direct' . $rencontre->getId(), (string) ($donnees['jeton'] ?? ''))) {
            return new JsonResponse(['erreur' => 'Page expirée : rechargez-la.'], Response::HTTP_FORBIDDEN);
        }
        $existantes = [];
        foreach ($this->actions->duMatch($rencontre) as $a) {
            $existantes[$a->getUuid()] = $a;
        }
        $avant = $this->etat($rencontre, $existantes);
        $joueurs = [];
        foreach ($this->direct->joueurs($rencontre, array_values($existantes)) as $j) {
            $joueurs[$j['id']] = true;
        }
        $moi = $this->moi();
        // Buts, cartons et changements ajoutés, corrigés ou supprimés : repris ensuite sur la feuille de match.
        $pourLaFeuille = [];
        // Tirs au but notés, corrigés ou supprimés : score de la séance recalculé.
        $tabChangee = false;
        foreach ((array) ($donnees['supprimes'] ?? []) as $uuid) {
            if (isset($existantes[$uuid]) && $existantes[$uuid]->modifiablePar($moi)) {
                $tabChangee = $tabChangee || $existantes[$uuid]->isTirAuBut();
                $pourLaFeuille[] = [SynchroFeuille::photoAction($existantes[$uuid]), null];
                $this->em->remove($existantes[$uuid]);
                unset($existantes[$uuid]);
            }
        }
        foreach ((array) ($donnees['actions'] ?? []) as $a) {
            $uuid = (string) ($a['uuid'] ?? '');
            $type = (string) ($a['type'] ?? '');
            if (!preg_match('/^[0-9a-f-]{8,36}$/', $uuid) || !ActionMatch::existe($type)) {
                continue;
            }
            $changement = ActionMatch::CHANGEMENT === $type;
            $joueurId = ActionMatch::deJoueur($type) ? (int) ($a['joueur'] ?? 0) : 0;
            // Changement : qui sort (joueur du match) et qui entre (s'il y en a un : un autre joueur du match).
            $entrant = (int) ($a['details']['entrant'] ?? 0);
            if ($changement && (!isset($joueurs[$joueurId]) || ($entrant && (!isset($joueurs[$entrant]) || $entrant === $joueurId)))) {
                continue;
            }
            // Sans joueur : un corner, ou un but contre son camp d'un adversaire.
            $sansJoueur = 'CO' === $type || ('B' === $type && 'csc' === ($a['details']['geste'] ?? null));
            if (ActionMatch::deJoueur($type) && !$sansJoueur && !isset($joueurs[$joueurId])) {
                continue; // action d'un joueur du club sans joueur du match
            }
            $action = $existantes[$uuid] ?? null;
            if ($action && !$action->modifiablePar($moi)) {
                continue; // action d'une autre tablette
            }
            $photo = $action ? SynchroFeuille::photoAction($action) : null;
            if (!$action) {
                if ($this->actions->findOneBy(['uuid' => $uuid])) {
                    continue; // uuid d'un autre match
                }
                $action = (new ActionMatch($rencontre, $uuid))->setAuteur($moi);
                $this->em->persist($action);
                $existantes[$uuid] = $action;
            }
            $action->setType($type)
                ->setJoueur($joueurId && isset($joueurs[$joueurId]) ? $this->em->getReference(Joueur::class, $joueurId) : null)
                ->placer((int) ($a['periode'] ?? 1), (int) ($a['minute'] ?? 0), (int) ($a['zx'] ?? 0), (int) ($a['zy'] ?? 1))
                ->setDetails(\is_array($a['details'] ?? null) ? $a['details'] : null);
            // Passeur décisif : un joueur du match, autre que le buteur ; sinon écarté.
            $details = $action->getDetails();
            if (isset($details['passeur']) && (!isset($joueurs[$details['passeur']]) || $details['passeur'] === $joueurId)) {
                unset($details['passeur']);
                $action->setDetails($details ?: null);
            }
            $tabChangee = $tabChangee || $action->isTirAuBut();
            // Renvoyée telle quelle (réseau revenu) : rien de nouveau pour la feuille.
            if ($photo !== SynchroFeuille::photoAction($action)) {
                $pourLaFeuille[] = [$photo, $action];
            }
        }
        if ($tabChangee) {
            $this->compterTab($rencontre, array_values($existantes));
        }
        $infos = (array) ($donnees['infos'] ?? []);
        if (\in_array($infos['meteo'] ?? null, Rencontre::METEOS, true)) {
            $rencontre->setMeteo($infos['meteo']);
        }
        if (\in_array($infos['terrain'] ?? null, Rencontre::TERRAINS, true)) {
            $rencontre->setTerrain($infos['terrain']);
        }
        if (\in_array($infos['vent'] ?? null, Rencontre::VENTS, true)) {
            $rencontre->setVent($infos['vent']);
        }
        if (\in_array($infos['temperature'] ?? null, array_map('strval', range(-15, 40)), true)) {
            $rencontre->setTemperature($infos['temperature']);
        }
        if (\is_array($donnees['chrono'] ?? null)) {
            $rencontre->majChronoDirect($donnees['chrono']);
        }
        // Coupe : suite d'une égalité choisie sur place (tirs au but directement, prolongations, nul).
        if (isset(Rencontre::EGALITES[(string) ($donnees['egalite'] ?? '')])) {
            $rencontre->setEgalite($donnees['egalite']);
        }
        // Titulaires choisis sur la tablette (joueurs du match seulement) ; la dernière tablette qui les change l'emporte.
        if (\is_array($donnees['titulaires'] ?? null)) {
            // Joueurs du match seulement, et pas plus que de joueurs sur le terrain.
            $choisis = array_values(array_filter(array_map('intval', $donnees['titulaires']), fn (int $id) => isset($joueurs[$id])));
            $rencontre->setTitulairesDirect(\array_slice($choisis, 0, $rencontre->joueursSurLeTerrain()));
        }
        // Numéros de maillot donnés sur la tablette (1 à 99, vide : effacé), pour les joueurs du match seulement.
        foreach ((array) ($donnees['numeros'] ?? []) as $joueurId => $numero) {
            if (isset($joueurs[(int) $joueurId])) {
                $n = is_numeric($numero) ? (int) $numero : null;
                $this->direct->numeroter($rencontre, (int) $joueurId, null !== $n && $n >= 1 && $n <= 99 ? $n : null);
            }
        }
        // Trace de la dernière modification : seulement quand cet envoi change vraiment quelque chose
        // (les tablettes envoient toutes les quelques secondes, souvent sans rien de nouveau).
        if ($avant !== $this->etat($rencontre, $existantes)) {
            $rencontre->marquerModifie($moi);
        }
        $this->em->flush();
        // Puis la feuille de match suit (buts, cartons, changements, score, temps de jeu).
        if ($pourLaFeuille) {
            $this->synchro->versFeuille($rencontre, $pourLaFeuille);
            $this->em->flush();
        }

        return new JsonResponse([
            'ok' => true,
            'total' => \count($existantes),
            'joueurs' => $this->direct->joueurs($rencontre, array_values($existantes)),
            'actions' => array_map(fn (ActionMatch $a) => $a->enTableau(), $this->actions->duMatch($rencontre)),
            'chrono' => $rencontre->getChronoDirect(),
            'titulaires' => $rencontre->titulairesPourLeDirect(),
            'maintenant' => self::maintenant(),
        ]);
    }

    /**
     * Score de la séance de tirs au but d'après les tirs notés en direct (marqués seulement), reporté sur le match
     * (côté domicile / extérieur) ; plus aucun tir : séance effacée.
     *
     * @param list<ActionMatch> $actions
     */
    private function compterTab(Rencontre $rencontre, array $actions): void
    {
        $tirs = array_filter($actions, fn (ActionMatch $a) => $a->isTirAuBut());
        if (!$tirs) {
            $rencontre->setTabDomicile(null)->setTabExterieur(null);

            return;
        }
        $nous = \count(array_filter($tirs, fn (ActionMatch $a) => 'TB' === $a->getType() && $a->isReussi()));
        $eux = \count(array_filter($tirs, fn (ActionMatch $a) => 'ATB' === $a->getType() && $a->isReussi()));
        $rencontre->setTabDomicile($rencontre->isADomicile() ? $nous : $eux)->setTabExterieur($rencontre->isADomicile() ? $eux : $nous);
    }

    /**
     * Ce que la tablette peut changer (actions, conditions, chrono, titulaires, numéros), pour savoir si un envoi
     * a modifié le match.
     *
     * @param array<string, ActionMatch> $actions
     */
    private function etat(Rencontre $rencontre, array $actions): string
    {
        return serialize([
            array_map(fn (ActionMatch $a) => $a->enTableau(), $actions),
            $rencontre->getMeteo(), $rencontre->getTerrain(), $rencontre->getVent(), $rencontre->getTemperature(),
            $rencontre->getChronoDirect(), $rencontre->getTitulairesDirect(), $rencontre->getNumerosDirect(),
        ]);
    }

    private function moi(): \App\Entity\Utilisateur
    {
        /** @var \App\Entity\Utilisateur $u */
        $u = $this->getUser();

        return $u;
    }

    /** « · sur corner de Paul Martin », « · sur penalty »… pour un but sur coup de pied arrêté (rien sinon). */
    private function origineDuBut(ActionMatch $a): string
    {
        $d = $a->getDetails() ?? [];
        if (!\in_array($a->getType(), ['B', 'BA'], true) || !isset(ActionMatch::SITUATIONS_BUT[$d['situation'] ?? ''])) {
            return '';
        }
        $passeur = isset($d['passeur']) ? $this->em->find(Joueur::class, $d['passeur']) : null;

        return ' · sur ' . mb_strtolower(ActionMatch::SITUATIONS_BUT[$d['situation']])
            . ($passeur && \in_array($d['situation'], ['corner', 'cfi'], true) ? ' de ' . $passeur->getPrenom() . ' ' . $passeur->getNom() : '');
    }

    /** Heure du serveur en millisecondes : les tablettes s'y alignent pour un chrono commun. */
    private static function maintenant(): int
    {
        return (int) floor(microtime(true) * 1000);
    }

    #[Route('/matchs/{id}/direct/bilan', name: 'app_match_direct_bilan', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function bilan(Request $request, Rencontre $rencontre): Response
    {
        $notees = $this->actions->duMatch($rencontre);
        // Complétées par la feuille de match (buts, cartons, remplacements saisis ou corrigés sur la feuille).
        $actions = $this->direct->avecFeuille($rencontre, $notees);
        $stats = $this->direct->statistiques($rencontre, $actions);
        // Carte du terrain (dessinée dans la page, comme le terrain du Match Live) : chaque action avec son endroit
        // précis (mètres dans le sens de notre attaque) et sa zone ; filtres par équipe, joueur et type dans la page.
        $carteActions = [];
        foreach ($actions as $a) {
            // Pas sur la carte : changements, cartons blancs, et ce que la feuille ne place pas sur le terrain.
            if (isset(ActionMatch::HORS_JEU[$a->getType()]) || $a->isTirAuBut() || str_starts_with($a->getUuid(), MatchDirect::SANS_ZONE) || $a->isHorsCarte()) {
                continue;
            }
            $d = $a->getDetails() ?? [];
            $point = \in_array($a->getType(), ['B', 'BA'], true) ? ($d['depart'] ?? null) : ($d['point'] ?? null);
            $corner = \in_array($a->getType(), ['CO', 'CA'], true);
            // Corner noté avant le choix du drapeau : dans le coin d'après son couloir.
            if ($corner && !$point) {
                $point = ['CO' === $a->getType() ? 105 : 0, 2 === $a->getZy() ? 68 : 0];
            }
            $carteActions[] = [
                'type' => $a->getType(),
                'eux' => $a->isAdverse(),
                'joueur' => $a->getJoueur()?->getId(),
                'minute' => $a->getMinute(),
                'zx' => $a->getZx(),
                'zy' => $a->getZy(),
                // Frappe cadrée ajoutée avec un but : le ballon du but la remplace sur le terrain.
                'avecBut' => str_starts_with($a->getUuid(), 'ffffffff'),
                'details' => array_filter([
                    'point' => $point ? [round(max(0.0, min(105.0, (float) $point[0])), 1), round((float) $point[1], 1)] : null,
                    // But sur corner, coup franc ou penalty : signalé sur le terrain (trajet du corner, pastille).
                    'situation' => $d['situation'] ?? null,
                ], fn ($v) => null !== $v) + ['point' => null],
                'libelle' => ActionMatch::libelleDe($a->getType()) . ($a->getJoueur() ? ' · ' . $a->getJoueur()->getPrenom() . ' ' . $a->getJoueur()->getNom() : '')
                    . $this->origineDuBut($a),
            ];
        }

        // D'où viennent les buts (nous et l'adversaire) : jeu, corner, coup franc, penalty ; corners obtenus et
        // transformés ; tireurs des corners et coups francs décisifs.
        $origines = ['nous' => [], 'eux' => []];
        $corners = ['nous' => 0, 'eux' => 0];
        $tireurs = [];
        $nbButs = 0;
        foreach ($actions as $a) {
            if (\in_array($a->getType(), ['CO', 'CA'], true)) {
                ++$corners['CO' === $a->getType() ? 'nous' : 'eux'];
            }
            if (!\in_array($a->getType(), ['B', 'BA'], true)) {
                continue;
            }
            ++$nbButs;
            $situation = $a->getDetails()['situation'] ?? 'jeu';
            $camp = 'BA' === $a->getType() ? 'eux' : 'nous';
            $origines[$camp][$situation] = ($origines[$camp][$situation] ?? 0) + 1;
            $passeur = $a->getDetails()['passeur'] ?? null;
            if ('nous' === $camp && $passeur && \in_array($situation, ['corner', 'cfi'], true) && ($j = $this->em->find(Joueur::class, $passeur))) {
                $tireurs[$passeur] ??= ['joueur' => $j, 'corner' => 0, 'cfi' => 0];
                ++$tireurs[$passeur][$situation];
            }
        }
        $analyseButs = $nbButs ? [
            'origines' => $origines,
            'corners' => $corners,
            'tireurs' => array_values($tireurs),
            'libelles' => ['jeu' => 'Action de jeu'] + ActionMatch::SITUATIONS_BUT,
        ] : null;

        // Schémas des buts : départ du ballon et point d'entrée dans le but (sans tracé noté : milieu de la zone, centre du but).
        $traces = ['marques' => [], 'encaisses' => []];
        foreach ($actions as $a) {
            // But de la feuille pas placé sur le terrain : compté, mais pas dessiné.
            if (!\in_array($a->getType(), ['B', 'BA'], true) || str_starts_with($a->getUuid(), MatchDirect::SANS_ZONE) || $a->isHorsCarte()) {
                continue;
            }
            $d = $a->getDetails() ?? [];
            $adverse = 'BA' === $a->getType();
            $traces[$adverse ? 'encaisses' : 'marques'][] = [
                'minute' => $a->getMinute(),
                'periode' => $a->getPeriode(),
                'nom' => $a->getJoueur() ? $a->getJoueur()->getPrenom() . ' ' . $a->getJoueur()->getNom() : ('csc' === ($d['geste'] ?? null) ? 'CSC' : ''),
                'geste' => ActionMatch::GESTES_BUT[$d['geste'] ?? ''] ?? null,
                'passeur' => isset($d['passeur']) ? $this->em->find(Joueur::class, $d['passeur'])?->getPrenom() . ' ' . $this->em->find(Joueur::class, $d['passeur'])?->getNom() : null,
                'situation' => $d['situation'] ?? null,
                'depart' => $d['depart'] ?? [($a->getZx() + 0.5) * 17.5, ($a->getZy() + 0.5) * 68 / 3],
                'arrivee' => $d['arrivee'] ?? [$adverse ? 0.0 : 105.0, 34.0],
            ];
        }

        // Changements : minute, qui sort, qui entre, motif.
        $changements = [];
        foreach ($actions as $a) {
            if (ActionMatch::EXCLUSION_TEMPORAIRE === $a->getType()) {
                $changements[] = ['minute' => $a->getMinute(), 'periode' => $a->getPeriode(), 'sortant' => $a->getJoueur(), 'entrant' => null, 'motif' => 'Carton blanc'];
            } elseif (ActionMatch::CHANGEMENT === $a->getType()) {
                $entrant = isset($a->getDetails()['entrant']) ? $this->em->find(Joueur::class, $a->getDetails()['entrant']) : null;
                $changements[] = ['minute' => $a->getMinute(), 'periode' => $a->getPeriode(), 'sortant' => $a->getJoueur(), 'entrant' => $entrant, 'motif' => $a->getDetails()['motif'] ?? 'Choix'];
            }
        }

        // Séance de tirs au but : chaque tir dans l'ordre, qui tire (nous) et s'il est marqué.
        $tirsAuBut = array_values(array_map(fn (ActionMatch $a) => ['nous' => 'TB' === $a->getType(), 'joueur' => $a->getJoueur(), 'reussi' => $a->isReussi()],
            array_filter($actions, fn (ActionMatch $a) => $a->isTirAuBut())));

        return $this->render('match_direct/bilan.html.twig', [
            'analyseButs' => $analyseButs,
            'situations' => ActionMatch::SITUATIONS_BUT,
            'tirsAuBut' => $tirsAuBut,
            'changements' => $changements,
            'notees' => \count($notees),
            'traces' => $traces,
            'rencontre' => $rencontre,
            'actions' => $actions,
            'stats' => $stats,
            'carte' => [
                'actions' => $carteActions,
                'joueurs' => $this->direct->joueurs($rencontre, $actions),
                'types' => array_map(fn (array $t) => $t[0], ActionMatch::ACTIONS) + ActionMatch::ADVERSES,
                'nous' => (string) ($rencontre->isADomicile() ? $rencontre->getDomicile() : $rencontre->getExterieur()),
                'adversaire' => (string) $rencontre->getAdversaire(),
                // Lien « carte de ce joueur » (fiche du joueur, tableau par joueur) : la carte s'ouvre sur lui.
                'joueur' => $request->query->getInt('joueur') ?: null,
            ],
            'types' => ActionMatch::ACTIONS,
            'adverses' => ActionMatch::ADVERSES,
            // Durée d'une mi-temps : temps additionnel affiché « 45+2' ».
            'mi' => intdiv($this->direct->duree($rencontre), 2),
            'buts_feuille' => \count($rencontre->getButs()),
            'modifiable' => $this->isGranted(ActiviteVoter::MODIFIER_MATCH, $rencontre),
        ]);
    }

    /**
     * Rattrapage : tout ce qui a été noté en direct (buts, cartons de nos joueurs, changements) repris sur la feuille
     * de match, sans doublon. D'habitude inutile : chaque envoi d'une tablette met déjà la feuille à jour
     * (SynchroFeuille) ; sert pour ce qui a été noté avant. Les cartons de l'adversaire restent dans le bilan.
     */
    #[Route('/matchs/{id}/direct/reporter', name: 'app_match_direct_reporter', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[IsGranted(ActiviteVoter::MODIFIER_MATCH, 'rencontre')]
    public function reporter(Request $request, Rencontre $rencontre): Response
    {
        if (!$this->isCsrfTokenValid('reporter' . $rencontre->getId(), $request->getPayload()->getString('_token'))) {
            throw $this->createAccessDeniedException();
        }
        // Rattrapage : tout ce qui a été noté en direct, repris sur la feuille (sans doublon : même but, même changement).
        $compte = $this->synchro->toutVersFeuille($rencontre);
        if (array_sum($compte)) {
            $rencontre->marquerModifie($this->moi());
        }
        $this->em->flush();
        $messages = array_filter([
            $compte['buts'] ? \sprintf('%d but%s', $compte['buts'], $compte['buts'] > 1 ? 's' : '') : null,
            $compte['cartons'] ? \sprintf('%d carton%s', $compte['cartons'], $compte['cartons'] > 1 ? 's' : '') : null,
            $compte['changements'] ? \sprintf('%d changement%s', $compte['changements'], $compte['changements'] > 1 ? 's' : '') : null,
        ]);
        if ($ajoutes = $this->synchro->ajoutes()) {
            $this->addFlash('info', 'Ajouté' . (\count($ajoutes) > 1 ? 's' : '') . ' à la feuille de match : ' . implode(', ', $ajoutes) . ' (vérifiez numéro et poste).');
        }
        $this->addFlash($messages ? 'succes' : 'info', $messages
            ? 'Feuille de match à jour : ' . implode(', ', $messages) . '.'
            : 'La feuille de match était déjà à jour.');

        // Retour vers la feuille de match pour la vérifier (ou la page d'où vient le bouton).
        return $this->redirectToRoute($request->getPayload()->getString('retour') === 'feuille' ? 'app_rencontre_modifier' : 'app_rencontre_voir', ['id' => $rencontre->getId()]);
    }
}
