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
            ],
            'meteos' => Rencontre::METEOS,
            'terrains' => Rencontre::TERRAINS,
        ]);
    }

    /**
     * Envoi de la tablette : actions nouvelles ou corrigées (même uuid : remplacée), actions supprimées,
     * météo et terrain. Réponse : nombre d'actions enregistrées.
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
        foreach ((array) ($donnees['supprimes'] ?? []) as $uuid) {
            if (isset($existantes[$uuid])) {
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
            if (!$action) {
                if ($this->actions->findOneBy(['uuid' => $uuid])) {
                    continue; // uuid d'un autre match
                }
                $action = new ActionMatch($rencontre, $uuid);
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
        $this->em->flush();

        return new JsonResponse(['ok' => true, 'total' => \count($existantes)]);
    }

    #[Route('/matchs/{id}/direct/bilan', name: 'app_match_direct_bilan', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function bilan(Request $request, Rencontre $rencontre): Response
    {
        $actions = $this->actions->duMatch($rencontre);
        $stats = $this->direct->statistiques($rencontre, $actions);
        $type = $request->query->getString('type');
        $joueur = $request->query->getInt('joueur');
        // Carte du terrain : une action (ou toutes), pour un joueur (ou toute l'équipe).
        $carte = array_fill(0, ActionMatch::COLONNES, array_fill(0, ActionMatch::COULOIRS, 0));
        foreach ($actions as $a) {
            if ((!$type || $a->getType() === $type) && (!$joueur || $a->getJoueur()?->getId() === $joueur)) {
                ++$carte[$a->getZx()][$a->getZy()];
            }
        }

        return $this->render('match_direct/bilan.html.twig', [
            'rencontre' => $rencontre,
            'actions' => $actions,
            'stats' => $stats,
            'carte' => $carte,
            'max_carte' => max(1, ...array_merge(...$carte)),
            'type' => ActionMatch::existe($type) ? $type : '',
            'joueur' => $joueur,
            'types' => ActionMatch::ACTIONS,
            'adverses' => ActionMatch::ADVERSES,
            'buts_feuille' => \count($rencontre->getButs()),
        ]);
    }

    /** Buts notés en direct reportés sur la feuille de match (si elle n'en a pas encore), score compris s'il manque. */
    #[Route('/matchs/{id}/direct/reporter', name: 'app_match_direct_reporter', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function reporter(Request $request, Rencontre $rencontre): Response
    {
        if (!$this->isCsrfTokenValid('reporter' . $rencontre->getId(), $request->getPayload()->getString('_token'))) {
            throw $this->createAccessDeniedException();
        }
        if (\count($rencontre->getButs())) {
            $this->addFlash('erreur', 'La feuille de match a déjà des buts : complétez-la à la main.');

            return $this->redirectToRoute('app_match_direct_bilan', ['id' => $rencontre->getId()]);
        }
        $mi = intdiv($this->direct->duree($rencontre), 2);
        $pour = $contre = 0;
        foreach ($this->actions->duMatch($rencontre) as $a) {
            if (!\in_array($a->getType(), ['B', 'BA'], true)) {
                continue;
            }
            // Temps additionnel : « 45+2 » plutôt que 47 en première période.
            $fin = 1 === $a->getPeriode() ? $mi : 2 * $mi;
            $but = (new But())->setAdverse('BA' === $a->getType())->setButeur($a->getJoueur())->setMinute(min($a->getMinute(), $fin));
            if ($a->getMinute() > $fin) {
                $but->setAdditionnel(min(20, $a->getMinute() - $fin));
            }
            $rencontre->addBut($but);
            'BA' === $a->getType() ? ++$contre : ++$pour;
        }
        if (null === $rencontre->getButsDomicile() && null === $rencontre->getButsExterieur()) {
            $rencontre->setButsDomicile($rencontre->isADomicile() ? $pour : $contre)->setButsExterieur($rencontre->isADomicile() ? $contre : $pour);
        }
        $this->em->flush();
        $this->addFlash('succes', \sprintf('%d but%s reporté%s sur la feuille de match. Ajoutez-y les passeurs et la façon de marquer si besoin.', $pour + $contre, $pour + $contre > 1 ? 's' : '', $pour + $contre > 1 ? 's' : ''));

        return $this->redirectToRoute('app_rencontre_voir', ['id' => $rencontre->getId()]);
    }
}
