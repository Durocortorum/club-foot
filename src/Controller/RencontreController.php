<?php

namespace App\Controller;

use App\Entity\But;
use App\Entity\Categorie;
use App\Entity\Joueur;
use App\Entity\Rencontre;
use App\Form\RencontreType;
use App\Repository\ButRepository;
use App\Repository\CategorieRepository;
use App\Repository\JoueurRepository;
use App\Repository\ParticipationRepository;
use App\Repository\RencontreRepository;
use App\Security\ActiviteVoter;
use App\Service\Apparence;
use App\Service\SuiviDisciplinaire;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Rapports de match et statistiques des joueurs.
 */
class RencontreController extends AbstractController
{
    public function __construct(private readonly ActiviteVoter $voter)
    {
    }

    #[Route('/matchs', name: 'app_rencontre_index', methods: ['GET'])]
    public function index(Request $request, RencontreRepository $rencontres, CategorieRepository $categories): Response
    {
        $secteurs = $this->voter->secteursMatchs();
        $filtres = $this->filtres($request, $rencontres, $categories);
        $filtres['q'] = trim($request->query->getString('q')) ?: null;
        $page = max(1, $request->query->getInt('page', 1));

        $resultats = $rencontres->rechercher($secteurs, $filtres['saison'], $filtres['categorie'], $filtres['q'], $page);

        return $this->render('rencontre/index.html.twig', [
            'rencontres' => $resultats,
            'total' => \count($resultats),
            'pages' => max(1, (int) ceil(\count($resultats) / RencontreRepository::PAR_PAGE)),
            'page' => $page,
            'bilan' => $rencontres->bilan($secteurs, $filtres['saison'], $filtres['categorie']),
            'saisons' => $rencontres->saisons(),
            'categories' => $this->categoriesVisibles($categories),
            'filtres' => $filtres,
            'peut_creer' => null === $secteurs || [] !== $secteurs,
        ]);
    }

    #[Route('/statistiques', name: 'app_statistiques', methods: ['GET'])]
    public function statistiques(
        Request $request,
        RencontreRepository $rencontres,
        ParticipationRepository $participations,
        ButRepository $buts,
        CategorieRepository $categories,
        SuiviDisciplinaire $discipline,
    ): Response {
        $secteurs = $this->voter->secteursMatchs();
        $filtres = $this->filtres($request, $rencontres, $categories);
        $filtres['vue'] = \in_array($v = $request->query->getString('vue'), ['equipe', 'joueurs', 'discipline'], true) ? $v : 'equipe';
        $tris = [
            'minutes' => 'Temps de jeu', 'matchs' => 'Matchs joués', 'titularisations' => 'Titularisations',
            'complets' => 'Matchs joués entièrement', 'convocations' => 'Convocations', 'remplacantDepart' => 'Remplaçant au départ',
            'nonUtilise' => 'Remplaçant non utilisé', 'entrees' => 'Entrées en cours de match', 'sorties' => 'Sorties en cours de match',
            'buts' => 'Buts', 'passes' => 'Passes décisives', 'note' => 'Note moyenne',
        ];
        $filtres['tri'] = isset($tris[$t = $request->query->getString('tri')]) ? $t : 'minutes';
        [$s, $c] = [$filtres['saison'], $filtres['categorie']];

        $vue = [
            'bilan' => $rencontres->bilan($secteurs, $s, $c),
            'saisons' => $rencontres->saisons(),
            'categories' => $this->categoriesVisibles($categories),
            'filtres' => $filtres,
            'tris' => $tris,
        ];
        if ('equipe' === $filtres['vue']) {
            $vue += [
                'analyse' => $buts->analyse($secteurs, $s, $c),
                'tranches' => ButRepository::TRANCHES,
                'phases' => But::PHASES + ['inconnue' => 'Non renseignée'],
            ];
        } elseif ('joueurs' === $filtres['vue']) {
            $stats = $participations->statistiques($secteurs, $s, $c);
            usort($stats['joueurs'], fn (array $a, array $b) => [$b[$filtres['tri']], $b['minutes'], $a['nom']] <=> [$a[$filtres['tri']], $a['minutes'], $b['nom']]);
            $vue['stats'] = $stats;
        } else {
            $cartons = $participations->cartons($secteurs, $s, $c);
            $vue += ['cartons' => $cartons, 'suivi' => $discipline->analyser($cartons, $rencontres->dernierMatch($secteurs, $s, $c))];
        }

        return $this->render('rencontre/statistiques.html.twig', $vue);
    }

    #[Route('/matchs/nouveau', name: 'app_rencontre_nouveau', methods: ['GET', 'POST'])]
    public function nouveau(Request $request, EntityManagerInterface $em, JoueurRepository $joueurs, Apparence $apparence): Response
    {
        if ([] === $this->voter->secteursMatchs()) {
            throw $this->createAccessDeniedException();
        }
        $rencontre = (new Rencontre())->setDate(new \DateTimeImmutable('today'))->setDomicile(mb_strtoupper($apparence->club()->getNom()));

        return $this->formulaire($request, $rencontre, $em, $joueurs, 'Nouveau match');
    }

    #[Route('/matchs/{id}', name: 'app_rencontre_voir', requirements: ['id' => '\d+'], methods: ['GET'])]
    #[IsGranted(ActiviteVoter::GERER, 'rencontre')]
    public function voir(Rencontre $rencontre): Response
    {
        return $this->render('rencontre/voir.html.twig', ['rencontre' => $rencontre]);
    }

    #[Route('/matchs/{id}/modifier', name: 'app_rencontre_modifier', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    #[IsGranted(ActiviteVoter::GERER, 'rencontre')]
    public function modifier(Request $request, Rencontre $rencontre, EntityManagerInterface $em, JoueurRepository $joueurs): Response
    {
        return $this->formulaire($request, $rencontre, $em, $joueurs, 'Modifier le match ' . $rencontre);
    }

    #[Route('/matchs/{id}/supprimer', name: 'app_rencontre_supprimer', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[IsGranted(ActiviteVoter::GERER, 'rencontre')]
    public function supprimer(Request $request, Rencontre $rencontre, EntityManagerInterface $em): Response
    {
        if ($this->isCsrfTokenValid('supprimer-match' . $rencontre->getId(), $request->getPayload()->getString('_token'))) {
            $em->remove($rencontre);
            $em->flush();
            $this->addFlash('succes', 'Match supprimé.');
        }

        return $this->redirectToRoute('app_rencontre_index');
    }

    private function formulaire(Request $request, Rencontre $rencontre, EntityManagerInterface $em, JoueurRepository $joueurs, string $titre): Response
    {
        $secteurs = $this->voter->secteursMatchs() ?? array_keys(Categorie::SECTEURS);
        $form = $this->createForm(RencontreType::class, $rencontre, [
            'secteurs' => $secteurs,
            'joueurs' => $this->joueursSelectionnables($rencontre, $secteurs, $joueurs),
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $em->persist($rencontre);
            $em->flush();
            $this->addFlash('succes', 'Match enregistré.');

            return $this->redirectToRoute('app_rencontre_voir', ['id' => $rencontre->getId()]);
        }

        return $this->render('rencontre/formulaire.html.twig', [
            'rencontre' => $rencontre,
            'form' => $form,
            'titre' => $titre,
        ]);
    }

    /**
     * Joueurs proposés sur la feuille de match : les joueurs actifs des secteurs
     * de l'utilisateur, plus ceux déjà inscrits (même devenus anciens).
     *
     * @param list<string> $secteurs
     *
     * @return list<Joueur>
     */
    private function joueursSelectionnables(Rencontre $rencontre, array $secteurs, JoueurRepository $joueurs): array
    {
        $liste = $joueurs->createQueryBuilder('j')
            ->join('j.categorie', 'c')
            ->andWhere('j.ancien = false')
            ->andWhere('c.secteur IN (:s)')->setParameter('s', $secteurs)
            ->getQuery()
            ->getResult();

        $deja = [];
        foreach ($rencontre->getParticipations() as $p) {
            $deja[] = $p->getJoueur();
        }
        foreach ($rencontre->getButs() as $b) {
            array_push($deja, $b->getButeur(), $b->getPasseur());
        }
        foreach ($rencontre->getRemplacements() as $r) {
            array_push($deja, $r->getSortant(), $r->getEntrant());
        }
        $parId = [];
        foreach (array_filter(array_merge($liste, $deja)) as $j) {
            $parId[$j->getId()] = $j;
        }
        uasort($parId, fn (Joueur $a, Joueur $b) => [$a->getNom(), $a->getPrenom()] <=> [$b->getNom(), $b->getPrenom()]);

        return array_values($parId);
    }

    /** @return array{saison: string|null, categorie: int|null} */
    private function filtres(Request $request, RencontreRepository $rencontres, CategorieRepository $categories): array
    {
        $saison = $request->query->has('saison') ? $request->query->getString('saison') : $rencontres->derniereSaison();
        $categorie = $request->query->getInt('categorie');

        return [
            'saison' => preg_match('#^\d{4}/\d{4}$#', $saison) ? $saison : null,
            // La catégorie mémorisée depuis un autre onglet peut ne pas être proposée ici.
            'categorie' => \in_array($categorie, array_map(fn (Categorie $c) => $c->getId(), $this->categoriesVisibles($categories)), true) ? $categorie : null,
        ];
    }

    /** @return list<Categorie> */
    private function categoriesVisibles(CategorieRepository $categories): array
    {
        $secteurs = $this->voter->secteursMatchs();

        return null === $secteurs
            ? $categories->findBy([], ['ordre' => 'ASC'])
            : $categories->findBy(['secteur' => $secteurs], ['ordre' => 'ASC']);
    }
}
