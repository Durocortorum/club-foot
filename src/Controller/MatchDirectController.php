<?php

namespace App\Controller;

use App\Entity\ActionMatch;
use App\Entity\Joueur;
use App\Entity\Rencontre;
use App\Repository\ActionMatchRepository;
use App\Security\ActiviteVoter;
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
    public function direct(Rencontre $rencontre): Response
    {
        $actions = $this->actions->duMatch($rencontre);

        return $this->render('match_direct/direct.html.twig', [
            'rencontre' => $rencontre,
            'config' => [
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
                'maintenant' => self::maintenant(),
            ],
            'meteos' => Rencontre::METEOS,
            'terrains' => Rencontre::TERRAINS,
            'vents' => Rencontre::VENTS,
            'temperatures' => array_map('strval', range(-15, 40)),
        ]);
    }

    /**
     * Échange avec chaque tablette, toutes les quelques secondes : elle envoie ses actions nouvelles ou corrigées
     * (même uuid : remplacée), ses suppressions, son chrono s'il a changé, la météo et le terrain ; elle reçoit toutes
     * les actions du match (celles des autres tablettes comprises), le chrono commun et l'heure du serveur.
     * Une action notée par un autre compte n'est ni corrigée ni supprimée.
     */
    #[Route('/matchs/{id}/direct/enregistrer', name: 'app_match_direct_enregistrer', requirements: ['id' => '\d+'], methods: ['POST'])]
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
        $joueurs = [];
        foreach ($this->direct->joueurs($rencontre, array_values($existantes)) as $j) {
            $joueurs[$j['id']] = true;
        }
        $moi = $this->moi();
        // Buts, cartons et changements ajoutés, corrigés ou supprimés : repris ensuite sur la feuille de match.
        $pourLaFeuille = [];
        foreach ((array) ($donnees['supprimes'] ?? []) as $uuid) {
            if (isset($existantes[$uuid]) && $existantes[$uuid]->modifiablePar($moi)) {
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
            // Renvoyée telle quelle (réseau revenu) : rien de nouveau pour la feuille.
            if ($photo !== SynchroFeuille::photoAction($action)) {
                $pourLaFeuille[] = [$photo, $action];
            }
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
        // Titulaires choisis sur la tablette (joueurs du match seulement) ; la dernière tablette qui les change l'emporte.
        if (\is_array($donnees['titulaires'] ?? null)) {
            $rencontre->setTitulairesDirect(array_values(array_filter(array_map('intval', $donnees['titulaires']), fn (int $id) => isset($joueurs[$id]))));
        }
        // Numéros de maillot donnés sur la tablette (1 à 99, vide : effacé), pour les joueurs du match seulement.
        foreach ((array) ($donnees['numeros'] ?? []) as $joueurId => $numero) {
            if (isset($joueurs[(int) $joueurId])) {
                $n = is_numeric($numero) ? (int) $numero : null;
                $this->direct->numeroter($rencontre, (int) $joueurId, null !== $n && $n >= 1 && $n <= 99 ? $n : null);
            }
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

    private function moi(): \App\Entity\Utilisateur
    {
        /** @var \App\Entity\Utilisateur $u */
        $u = $this->getUser();

        return $u;
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
        $type = $request->query->getString('type');
        $joueur = $request->query->getInt('joueur');
        // Carte du terrain : une action, toutes nos actions (« nous »), tout ce qu'a fait l'adversaire (« adverse »)
        // ou tout ; pour un joueur ou toute l'équipe.
        // Deux cartes : nos actions et celles de l'adversaire (couleurs différentes ; zones coupées en deux pour « toutes »).
        $vide = array_fill(0, ActionMatch::COLONNES, array_fill(0, ActionMatch::COULOIRS, 0));
        $cartes = ['nous' => $vide, 'adverse' => $vide];
        // Corners : pas de zone colorée, un petit rond avec leur nombre dans le coin du terrain (haut ou bas) ;
        // les nôtres côté adverse, ceux de l'adversaire de notre côté.
        $coins = ['nous' => ['haut' => 0, 'bas' => 0], 'adverse' => ['haut' => 0, 'bas' => 0]];
        foreach ($actions as $a) {
            $retenue = match ($type) {
                '', 'nous', 'adverse' => true,
                default => $a->getType() === $type,
            };
            // Pas sur la carte : changements, cartons blancs, et ce que la feuille ne place pas sur le terrain.
            if (!$retenue || isset(ActionMatch::HORS_JEU[$a->getType()]) || str_starts_with($a->getUuid(), MatchDirect::SANS_ZONE) || $a->isHorsCarte()
                || ($joueur && $a->getJoueur()?->getId() !== $joueur)) {
                continue;
            }
            $camp = $a->isAdverse() ? 'adverse' : 'nous';
            if (\in_array($a->getType(), ['CO', 'CA'], true)) {
                ++$coins[$camp][2 === $a->getZy() ? 'bas' : 'haut'];
            } else {
                ++$cartes[$camp][$a->getZx()][$a->getZy()];
            }
        }
        if ('nous' === $type || ($type && isset(ActionMatch::ACTIONS[$type])) || $joueur) {
            $cartes['adverse'] = null;
            $coins['adverse'] = null;
        } elseif ('adverse' === $type || isset(ActionMatch::ADVERSES[$type])) {
            $cartes['nous'] = null;
            $coins['nous'] = null;
        }
        $carte = $cartes['nous'] ?? $cartes['adverse'];

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
                'nom' => $a->getJoueur() ? $a->getJoueur()->getPrenom() . ' ' . $a->getJoueur()->getNom() : ('csc' === ($d['geste'] ?? null) ? 'CSC' : ''),
                'geste' => ActionMatch::GESTES_BUT[$d['geste'] ?? ''] ?? null,
                'passeur' => isset($d['passeur']) ? $this->em->find(Joueur::class, $d['passeur'])?->getPrenom() . ' ' . $this->em->find(Joueur::class, $d['passeur'])?->getNom() : null,
                'depart' => $d['depart'] ?? [($a->getZx() + 0.5) * 17.5, ($a->getZy() + 0.5) * 68 / 3],
                'arrivee' => $d['arrivee'] ?? [$adverse ? 0.0 : 105.0, 34.0],
            ];
        }

        // Changements : minute, qui sort, qui entre, motif.
        $changements = [];
        foreach ($actions as $a) {
            if (ActionMatch::EXCLUSION_TEMPORAIRE === $a->getType()) {
                $changements[] = ['minute' => $a->getMinute(), 'sortant' => $a->getJoueur(), 'entrant' => null, 'motif' => 'Carton blanc'];
            } elseif (ActionMatch::CHANGEMENT === $a->getType()) {
                $entrant = isset($a->getDetails()['entrant']) ? $this->em->find(Joueur::class, $a->getDetails()['entrant']) : null;
                $changements[] = ['minute' => $a->getMinute(), 'sortant' => $a->getJoueur(), 'entrant' => $entrant, 'motif' => $a->getDetails()['motif'] ?? 'Choix'];
            }
        }

        return $this->render('match_direct/bilan.html.twig', [
            'changements' => $changements,
            'notees' => \count($notees),
            'traces' => $traces,
            'rencontre' => $rencontre,
            'actions' => $actions,
            'stats' => $stats,
            'carte' => $carte,
            'cartes' => $cartes,
            'coins' => $coins,
            'max_carte' => max(1, ...array_merge(...array_merge(...array_values(array_filter($cartes))))),
            'type' => ActionMatch::existe($type) || \in_array($type, ['nous', 'adverse'], true) ? $type : '',
            'joueur' => $joueur,
            'types' => ActionMatch::ACTIONS,
            'adverses' => ActionMatch::ADVERSES,
            'buts_feuille' => \count($rencontre->getButs()),
        ]);
    }

    /**
     * Rattrapage : tout ce qui a été noté en direct (buts, cartons de nos joueurs, changements) repris sur la feuille
     * de match, sans doublon. D'habitude inutile : chaque envoi d'une tablette met déjà la feuille à jour
     * (SynchroFeuille) ; sert pour ce qui a été noté avant. Les cartons de l'adversaire restent dans le bilan.
     */
    #[Route('/matchs/{id}/direct/reporter', name: 'app_match_direct_reporter', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function reporter(Request $request, Rencontre $rencontre): Response
    {
        if (!$this->isCsrfTokenValid('reporter' . $rencontre->getId(), $request->getPayload()->getString('_token'))) {
            throw $this->createAccessDeniedException();
        }
        // Rattrapage : tout ce qui a été noté en direct, repris sur la feuille (sans doublon : même but, même changement).
        $compte = $this->synchro->toutVersFeuille($rencontre);
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
