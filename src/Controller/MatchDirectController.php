<?php

namespace App\Controller;

use App\Entity\ActionMatch;
use App\Entity\But;
use App\Entity\Joueur;
use App\Entity\Rencontre;
use App\Repository\ActionMatchRepository;
use App\Security\ActiviteVoter;
use App\Service\MatchDirect;
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
                'maintenant' => self::maintenant(),
            ],
            'meteos' => Rencontre::METEOS,
            'terrains' => Rencontre::TERRAINS,
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
        foreach ((array) ($donnees['supprimes'] ?? []) as $uuid) {
            if (isset($existantes[$uuid]) && $existantes[$uuid]->modifiablePar($moi)) {
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
            $joueurId = isset(ActionMatch::ACTIONS[$type]) ? (int) ($a['joueur'] ?? 0) : 0;
            if (isset(ActionMatch::ACTIONS[$type]) && 'CO' !== $type && !isset($joueurs[$joueurId])) {
                continue; // action d'un joueur du club sans joueur du match
            }
            $action = $existantes[$uuid] ?? null;
            if ($action && !$action->modifiablePar($moi)) {
                continue; // action d'une autre tablette
            }
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
                ->placer((int) ($a['periode'] ?? 1), (int) ($a['minute'] ?? 0), (int) ($a['zx'] ?? 0), (int) ($a['zy'] ?? 1));
        }
        $infos = (array) ($donnees['infos'] ?? []);
        if (\in_array($infos['meteo'] ?? null, Rencontre::METEOS, true)) {
            $rencontre->setMeteo($infos['meteo']);
        }
        if (\in_array($infos['terrain'] ?? null, Rencontre::TERRAINS, true)) {
            $rencontre->setTerrain($infos['terrain']);
        }
        if (\is_array($donnees['chrono'] ?? null)) {
            $rencontre->majChronoDirect($donnees['chrono']);
        }
        // Numéros de maillot donnés sur la tablette (1 à 99, vide : effacé), pour les joueurs du match seulement.
        foreach ((array) ($donnees['numeros'] ?? []) as $joueurId => $numero) {
            if (isset($joueurs[(int) $joueurId])) {
                $n = is_numeric($numero) ? (int) $numero : null;
                $this->direct->numeroter($rencontre, (int) $joueurId, null !== $n && $n >= 1 && $n <= 99 ? $n : null);
            }
        }
        $this->em->flush();

        return new JsonResponse([
            'ok' => true,
            'total' => \count($existantes),
            'joueurs' => $this->direct->joueurs($rencontre, array_values($existantes)),
            'actions' => array_map(fn (ActionMatch $a) => $a->enTableau(), $this->actions->duMatch($rencontre)),
            'chrono' => $rencontre->getChronoDirect(),
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
        $actions = $this->actions->duMatch($rencontre);
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
            if (!$retenue || ($joueur && $a->getJoueur()?->getId() !== $joueur)) {
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

        return $this->render('match_direct/bilan.html.twig', [
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
     * Report sur la feuille de match de ce qui a été noté en direct :
     * - les buts, si la feuille n'en a pas encore (score compris s'il manque) ;
     * - les cartons de nos joueurs déjà sur la feuille et sans carton (jaune, rouge, ou jaune puis rouge, avec la minute).
     * Les cartons de l'adversaire restent dans le bilan (la feuille ne les prévoit pas).
     */
    #[Route('/matchs/{id}/direct/reporter', name: 'app_match_direct_reporter', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function reporter(Request $request, Rencontre $rencontre): Response
    {
        if (!$this->isCsrfTokenValid('reporter' . $rencontre->getId(), $request->getPayload()->getString('_token'))) {
            throw $this->createAccessDeniedException();
        }
        $actions = $this->actions->duMatch($rencontre);
        $messages = [];

        // Cartons : par joueur, dans l'ordre du match.
        $cartons = [];
        foreach ($actions as $a) {
            if (\in_array($a->getType(), ['CJ', 'CR'], true) && $a->getJoueur()) {
                $cartons[$a->getJoueur()->getId()][] = $a;
            }
        }
        $reportes = 0;
        $horsFeuille = [];
        foreach ($cartons as $liste) {
            $participation = null;
            foreach ($rencontre->getParticipations() as $p) {
                if ($p->getJoueur()?->getId() === $liste[0]->getJoueur()->getId()) {
                    $participation = $p;
                }
            }
            if (!$participation) {
                $horsFeuille[] = $liste[0]->getJoueur()->getPrenom() . ' ' . $liste[0]->getJoueur()->getNom();
                continue;
            }
            if ($participation->getCarton()) {
                continue;
            }
            $types = array_map(fn (ActionMatch $a) => $a->getType(), $liste);
            $rouge = array_values(array_filter($liste, fn (ActionMatch $a) => 'CR' === $a->getType()))[0] ?? null;
            [$code, $minute] = match (true) {
                \count(array_keys($types, 'CJ')) >= 2 => ['J+R', $liste[1]->getMinute()],
                null !== $rouge && \in_array('CJ', $types, true) => ['J+R', $rouge->getMinute()],
                null !== $rouge => ['RJ', $rouge->getMinute()],
                default => ['JJ', $liste[0]->getMinute()],
            };
            $participation->setCarton($code)->setMinuteCarton((string) $minute);
            ++$reportes;
        }
        if ($reportes) {
            $messages[] = \sprintf('%d carton%s', $reportes, $reportes > 1 ? 's' : '');
        }

        $pour = $contre = 0;
        $butsNotes = array_filter($actions, fn (ActionMatch $a) => \in_array($a->getType(), ['B', 'BA'], true));
        if ($butsNotes && \count($rencontre->getButs())) {
            $this->addFlash('info', 'La feuille de match a déjà des buts : ceux notés en direct n\'y sont pas ajoutés.');
            $butsNotes = [];
        }
        $mi = intdiv($this->direct->duree($rencontre), 2);
        foreach ($butsNotes as $a) {
            // Temps additionnel : « 45+2 » plutôt que 47 en première période.
            $fin = 1 === $a->getPeriode() ? $mi : 2 * $mi;
            $but = (new But())->setAdverse('BA' === $a->getType())->setButeur($a->getJoueur())->setMinute(min($a->getMinute(), $fin));
            if ($a->getMinute() > $fin) {
                $but->setAdditionnel(min(20, $a->getMinute() - $fin));
            }
            $rencontre->addBut($but);
            'BA' === $a->getType() ? ++$contre : ++$pour;
        }
        if ($butsNotes && null === $rencontre->getButsDomicile() && null === $rencontre->getButsExterieur()) {
            $rencontre->setButsDomicile($rencontre->isADomicile() ? $pour : $contre)->setButsExterieur($rencontre->isADomicile() ? $contre : $pour);
        }
        if ($pour + $contre) {
            array_unshift($messages, \sprintf('%d but%s', $pour + $contre, $pour + $contre > 1 ? 's' : ''));
        }
        $this->em->flush();
        if ($horsFeuille) {
            $this->addFlash('info', 'Cartons non reportés (joueur absent de la feuille de match) : ' . implode(', ', $horsFeuille) . '.');
        }
        $this->addFlash($messages ? 'succes' : 'info', $messages
            ? implode(' et ', $messages) . ' reporté' . ((($pour + $contre) + $reportes) > 1 ? 's' : '') . ' sur la feuille de match. Complétez-la si besoin (passeurs, façon de marquer, motif des cartons).'
            : 'Rien de nouveau à reporter sur la feuille de match.');

        return $this->redirectToRoute('app_rencontre_voir', ['id' => $rencontre->getId()]);
    }
}
