<?php

namespace App\Controller;

use App\Entity\Rencontre;
use App\Entity\TableauTactique;
use App\Entity\Utilisateur;
use App\Repository\ConvoqueRepository;
use App\Repository\TableauTactiqueRepository;
use App\Security\ActiviteVoter;
use App\Service\CompositionTerrain;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Tableau tactique d'un match : causerie et préparation. Plusieurs tableaux par match (organisation, pressing,
 * coups de pied arrêtés…), chacun avec la formation sur le terrain, flèches, zones, étapes animées et consignes ;
 * présentation plein écran ; partage facultatif avec les familles des joueurs convoqués.
 */
class TactiqueController extends AbstractController
{
    public function __construct(
        private readonly TableauTactiqueRepository $tableaux,
        private readonly CompositionTerrain $terrain,
        private readonly EntityManagerInterface $em,
    ) {
    }

    #[Route('/matchs/{id}/tactique', name: 'app_tactique', requirements: ['id' => '\d+'], methods: ['GET'])]
    #[IsGranted(ActiviteVoter::GERER, 'rencontre')]
    public function index(Rencontre $rencontre): Response
    {
        return $this->render('tactique/index.html.twig', [
            'rencontre' => $rencontre,
            'tableaux' => $this->tableaux->duMatch($rencontre),
            'modifiable' => $this->isGranted(ActiviteVoter::MODIFIER_MATCH, $rencontre),
        ]);
    }

    #[Route('/matchs/{id}/tactique/presenter', name: 'app_tactique_presenter', requirements: ['id' => '\d+'], methods: ['GET'])]
    #[IsGranted(ActiviteVoter::GERER, 'rencontre')]
    public function presenter(Rencontre $rencontre): Response
    {
        $tableaux = $this->tableaux->duMatch($rencontre);
        if (!$tableaux) {
            $this->addFlash('info', 'Créez d\'abord un tableau à présenter.');

            return $this->redirectToRoute('app_tactique', ['id' => $rencontre->getId()]);
        }

        return $this->render('tactique/presenter.html.twig', ['rencontre' => $rencontre, 'tableaux' => $tableaux]);
    }

    #[Route('/matchs/{id}/tactique/nouveau', name: 'app_tactique_nouveau', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    #[IsGranted(ActiviteVoter::MODIFIER_MATCH, 'rencontre')]
    public function nouveau(Request $request, Rencontre $rencontre): Response
    {
        $tableau = (new TableauTactique($rencontre))->setOrdre($this->tableaux->ordreSuivant($rencontre))
            ->setTitre($this->tableaux->duMatch($rencontre) ? '' : 'Notre organisation')
            ->setSchema($this->schemaDeDepart($rencontre));

        return $this->formulaire($request, $tableau, 'Nouveau tableau tactique');
    }

    #[Route('/matchs/tactique/{id}/modifier', name: 'app_tactique_modifier', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function modifier(Request $request, TableauTactique $tableau): Response
    {
        $this->denyAccessUnlessGranted(ActiviteVoter::MODIFIER_MATCH, $tableau->getRencontre());

        return $this->formulaire($request, $tableau, 'Modifier « ' . $tableau->getTitre() . ' »');
    }

    #[Route('/matchs/tactique/{id}/dupliquer', name: 'app_tactique_dupliquer', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function dupliquer(Request $request, TableauTactique $tableau): Response
    {
        $rencontre = $tableau->getRencontre();
        $this->denyAccessUnlessGranted(ActiviteVoter::MODIFIER_MATCH, $rencontre);
        if ($this->isCsrfTokenValid('tactique' . $tableau->getId(), $request->getPayload()->getString('_token'))) {
            $copie = (new TableauTactique($rencontre))->setTitre(mb_substr($tableau->getTitre() . ' (copie)', 0, 80))
                ->setConsignes($tableau->getConsignes())->setSchema($tableau->getSchema())
                ->setOrdre($this->tableaux->ordreSuivant($rencontre));
            $this->tracer($copie);
            $this->em->persist($copie);
            $this->em->flush();

            return $this->redirectToRoute('app_tactique_modifier', ['id' => $copie->getId()]);
        }

        return $this->redirectToRoute('app_tactique', ['id' => $rencontre->getId()]);
    }

    /** Monte ou descend un tableau dans l'ordre de présentation. */
    #[Route('/matchs/tactique/{id}/deplacer', name: 'app_tactique_deplacer', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function deplacer(Request $request, TableauTactique $tableau): Response
    {
        $rencontre = $tableau->getRencontre();
        $this->denyAccessUnlessGranted(ActiviteVoter::MODIFIER_MATCH, $rencontre);
        if ($this->isCsrfTokenValid('tactique' . $tableau->getId(), $request->getPayload()->getString('_token'))) {
            $liste = $this->tableaux->duMatch($rencontre);
            $i = array_search($tableau, $liste, true);
            $j = 'monter' === $request->getPayload()->getString('sens') ? $i - 1 : $i + 1;
            if (false !== $i && isset($liste[$j])) {
                [$liste[$i], $liste[$j]] = [$liste[$j], $liste[$i]];
                foreach ($liste as $k => $t) {
                    $t->setOrdre($k + 1);
                }
                $this->em->flush();
            }
        }

        return $this->redirectToRoute('app_tactique', ['id' => $rencontre->getId()]);
    }

    #[Route('/matchs/tactique/{id}/supprimer', name: 'app_tactique_supprimer', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function supprimer(Request $request, TableauTactique $tableau): Response
    {
        $rencontre = $tableau->getRencontre();
        $this->denyAccessUnlessGranted(ActiviteVoter::MODIFIER_MATCH, $rencontre);
        if ($this->isCsrfTokenValid('supprimer-tactique' . $tableau->getId(), $request->getPayload()->getString('_token'))) {
            $this->em->remove($tableau);
            $this->em->flush();
            $this->addFlash('succes', 'Tableau supprimé.');
        }

        return $this->redirectToRoute('app_tactique', ['id' => $rencontre->getId()]);
    }

    /**
     * Espace famille : tableaux que l'éducateur montre aux joueurs convoqués, pour un match où l'un des enfants
     * (ou le joueur lui-même) est convoqué.
     */
    #[Route('/espace/match/{id}/tactique', name: 'app_espace_tactique', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function espace(Rencontre $rencontre, ConvoqueRepository $convoques): Response
    {
        $moi = $this->getUser();
        $convoque = $moi instanceof Utilisateur && $moi->isFamille()
            && array_filter($convoques->aVenir($moi->getJoueurs()), fn ($c) => $c->getConvocation()->getRencontre() === $rencontre);
        $tableaux = $convoque ? $this->tableaux->duMatch($rencontre, true) : [];
        if (!$tableaux) {
            throw $this->createNotFoundException();
        }

        return $this->render('tactique/espace.html.twig', ['rencontre' => $rencontre, 'tableaux' => $tableaux]);
    }

    private function formulaire(Request $request, TableauTactique $tableau, string $titre): Response
    {
        $rencontre = $tableau->getRencontre();
        $erreur = null;
        if ($request->isMethod('POST')) {
            $donnees = $request->getPayload();
            if (!$this->isCsrfTokenValid('tableau-tactique', $donnees->getString('_token'))) {
                throw $this->createAccessDeniedException();
            }
            $schema = json_decode($donnees->getString('schema'), true);
            $tableau->setTitre($donnees->getString('titre'))
                ->setConsignes($donnees->getString('consignes'))
                ->setPartage($donnees->getBoolean('partage'))
                ->setSchema(\is_array($schema) ? $schema : []);
            if ('' === $tableau->getTitre()) {
                $erreur = 'Donnez un titre au tableau (ex. : Organisation, Pressing, Corners).';
            } else {
                $this->tracer($tableau);
                $this->em->persist($tableau);
                $this->em->flush();
                $this->addFlash('succes', 'Tableau « ' . $tableau->getTitre() . ' » enregistré.');

                return $this->redirectToRoute('app_tactique', ['id' => $rencontre->getId()]);
            }
        }

        return $this->render('tactique/formulaire.html.twig', [
            'titre' => $titre,
            'tableau' => $tableau,
            'rencontre' => $rencontre,
            'formations' => $formations = $this->formations(),
            // Formation proposée : celle du match, sinon celle d'usage pour le nombre de joueurs (à 11, à 9, à 8).
            'formation_defaut' => isset($formations[(string) $rencontre->getSysteme()]) ? $rencontre->getSysteme()
                : (['8' => '3 3 1', '9' => '3 3 2'][(string) $rencontre->joueursSurLeTerrain()] ?? '4 4 2'),
            'erreur' => $erreur,
        ]);
    }

    /**
     * Formations proposées : places des joueurs (en %) de chaque système de jeu, par format (à 11, à 9, à 8).
     *
     * @return array<string, list<array{0: float, 1: float}>>
     */
    private function formations(): array
    {
        $formations = [];
        foreach (Rencontre::SYSTEMES as $systemes) {
            foreach ($systemes as $s) {
                $joueurs = str_contains($s, 'losange') ? 11 : array_sum(array_map('intval', str_split(str_replace(' ', '', $s)))) + 1;
                $formations[$s] = $this->terrain->placesDuSysteme($s, $joueurs);
            }
        }

        return $formations;
    }

    /**
     * Premier tableau : notre composition du match (titulaires de la feuille à leur place, avec leur numéro),
     * sinon l'organisation du match (ou celle d'usage pour la catégorie), joueurs numérotés 1, 2, 3…
     *
     * @return array<string, mixed>
     */
    private function schemaDeDepart(Rencontre $rencontre): array
    {
        $places = [];
        foreach ($this->terrain->placer($rencontre)['titulaires'] as $t) {
            $places[] = [$t['x'], $t['y'], (string) ($t['participation']->getNumero() ?? '')];
        }
        if (!$places) {
            $systeme = $rencontre->isPlacementEducateur() ? null : $rencontre->getSysteme();
            foreach ($this->terrain->placesDuSysteme($systeme, $rencontre->joueursSurLeTerrain()) as $k => [$x, $y]) {
                $places[] = [$x, $y, (string) ($k + 1)];
            }
        }
        // Même passage du % au terrain entier que schema_controller.js (placerFormation) : notre but à gauche.
        $objets = array_map(fn (array $p) => [
            't' => 'joueur', 'c' => 'rouge', 'n' => $p[2],
            'x' => round(3 + 105 * (0.03 + 0.85 * $p[1] / 100), 1), 'y' => round(3 + 68 * $p[0] / 100, 1),
        ], $places);

        return ['terrain' => 'entier', 'objets' => $objets];
    }

    private function tracer(TableauTactique $tableau): void
    {
        if (($moi = $this->getUser()) instanceof Utilisateur) {
            $tableau->marquerModifie($moi);
        }
    }
}
