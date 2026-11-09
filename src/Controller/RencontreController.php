<?php

namespace App\Controller;

use App\Entity\But;
use App\Entity\Categorie;
use App\Entity\Convoque;
use App\Entity\Joueur;
use App\Entity\Participation;
use App\Entity\Rencontre;
use App\Form\RencontreType;
use App\Repository\ButRepository;
use App\Repository\CategorieRepository;
use App\Repository\ConvocationRepository;
use App\Repository\JoueurRepository;
use App\Repository\ParticipationRepository;
use App\Repository\RencontreRepository;
use App\Security\ActiviteVoter;
use App\Service\Apparence;
use App\Service\BilanEquipe;
use App\Service\ImportMatchs;
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
    /** Clé de session du dernier import de calendrier (matchs créés, équipe, heure). */
    private const DERNIER_IMPORT = 'dernier_import_matchs';
    /** Le bouton « Annuler cet import » reste proposé 24 heures. */
    private const DUREE_ANNULATION = 86400;

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
            'dernier_import' => ($i = $request->getSession()->get(self::DERNIER_IMPORT)) && $i['ids'] && time() - $i['le'] < self::DUREE_ANNULATION ? $i : null,
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
        BilanEquipe $bilanEquipe,
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
            // Meilleurs buteurs et passeurs de la période (5 premiers, au moins un but ou une passe).
            $joueurs = $participations->statistiques($secteurs, $s, $c)['joueurs'];
            $meilleurs = function (string $cle) use ($joueurs): array {
                $liste = array_values(array_filter($joueurs, fn (array $j) => $j[$cle] > 0));
                usort($liste, fn (array $a, array $b) => [$b[$cle], $a['nom']] <=> [$a[$cle], $b['nom']]);

                return \array_slice($liste, 0, 5);
            };
            $vue += [
                'bilan_equipe' => $bilanEquipe->analyser($rencontres->filtrer($rencontres->createQueryBuilder('r'), $secteurs, $s, $c)->getQuery()->getResult()),
                'buteurs' => $meilleurs('buts'),
                'passeurs' => $meilleurs('passes'),
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

    /**
     * Calendrier copié depuis un site (Transfermarkt, FFF…) : lu, montré, puis les matchs cochés sont créés.
     * Un match de la même équipe à la même date n'est pas recréé.
     */
    #[Route('/matchs/importer', name: 'app_rencontre_importer', methods: ['GET', 'POST'])]
    public function importer(
        Request $request,
        CategorieRepository $categories,
        RencontreRepository $rencontres,
        ImportMatchs $import,
        Apparence $apparence,
        EntityManagerInterface $em,
    ): Response {
        if ([] === $this->voter->secteursMatchs()) {
            throw $this->createAccessDeniedException();
        }
        $visibles = $this->categoriesVisibles($categories);
        $donnees = $request->getPayload();
        $idCategorie = $request->isMethod('POST') ? $donnees->getInt('categorie') : $request->query->getInt('categorie');
        $categorie = array_values(array_filter($visibles, fn (Categorie $c) => $c->getId() === $idCategorie))[0] ?? null;
        $competition = \in_array($c = $donnees->getString('competition'), Rencontre::COMPETITIONS, true) ? $c : 'Championnat';
        $texte = $donnees->getString('texte');
        $scores = !$request->isMethod('POST') || $donnees->getBoolean('scores');
        $analyse = null;
        $deja = [];

        if ($request->isMethod('POST') && $this->isCsrfTokenValid('importer-matchs', $donnees->getString('_token'))) {
            if (!$categorie) {
                $this->addFlash('erreur', 'Choisissez l\'équipe à qui sont ces matchs.');
            } elseif (!($analyse = $import->analyser($texte))['matchs']) {
                $this->addFlash('erreur', 'Aucun match reconnu : chaque match doit avoir une date (« dim. 23 août 2026 » ou « 23/08/2026 ») suivie des deux équipes.');
                $analyse = null;
            } else {
                foreach ($analyse['matchs'] as $i => $m) {
                    if ($existant = $rencontres->findOneBy(['categorie' => $categorie, 'date' => $m['date']])) {
                        $deja[$i] = $existant;
                    }
                }
            }

            if ($analyse && 'importer' === $donnees->getString('action')) {
                $club = mb_strtoupper($apparence->club()->getNom());
                $choisis = array_map('intval', $donnees->all('lignes'));
                $crees = [];
                foreach ($analyse['matchs'] as $i => $m) {
                    if (!\in_array($i, $choisis, true) || isset($deja[$i])) {
                        continue;
                    }
                    $r = (new Rencontre())
                        ->setDate($m['date'])
                        ->setHeure($m['heure'])
                        ->setCategorie($categorie)
                        ->setCompetition($m['competition'] ?? $competition)
                        ->setDomicile($m['aDomicile'] ? $club : $m['adversaire'])
                        ->setExterieur($m['aDomicile'] ? $m['adversaire'] : $club);
                    if ($scores && null !== $m['butsDomicile']) {
                        $r->setButsDomicile($m['butsDomicile'])->setButsExterieur($m['butsExterieur']);
                    }
                    $em->persist($r);
                    $crees[] = $r;
                }
                $em->flush();
                // Retenu pour pouvoir annuler tout l'import depuis la liste des matchs.
                $request->getSession()->set(self::DERNIER_IMPORT, [
                    'ids' => array_map(fn (Rencontre $r) => $r->getId(), $crees),
                    'categorie' => (string) $categorie,
                    'le' => time(),
                ]);
                $this->addFlash('succes', \sprintf('%d match%s importé%s pour %s.', \count($crees), \count($crees) > 1 ? 's' : '', \count($crees) > 1 ? 's' : '', $categorie));

                return $this->redirectToRoute('app_rencontre_index', [
                    'categorie' => $categorie->getId(),
                    'saison' => Rencontre::saisonDe(($crees[0] ?? null)?->getDate() ?? $analyse['matchs'][0]['date']),
                ]);
            }
        }

        return $this->render('rencontre/importer.html.twig', [
            'categories' => $visibles,
            'categorie' => $categorie,
            'competitions' => Rencontre::COMPETITIONS,
            'competition' => $competition,
            'texte' => $texte,
            'scores' => $scores,
            'analyse' => $analyse,
            'deja' => $deja,
        ]);
    }

    /**
     * Annule le dernier import : supprime les matchs qu'il a créés, sauf ceux déjà complétés
     * (feuille de match, buts, remplacements ou convocation), qui sont gardés.
     */
    #[Route('/matchs/importer/annuler', name: 'app_rencontre_import_annuler', methods: ['POST'])]
    public function annulerImport(Request $request, RencontreRepository $rencontres, ConvocationRepository $convocations, EntityManagerInterface $em): Response
    {
        $import = $request->getSession()->get(self::DERNIER_IMPORT);
        if (!$import || !$this->isCsrfTokenValid('annuler-import', $request->getPayload()->getString('_token'))) {
            return $this->redirectToRoute('app_rencontre_index');
        }
        $this->addFlash('succes', 'Import annulé : ' . $this->effacer($rencontres->findBy(['id' => $import['ids']]), $convocations, $em));
        $request->getSession()->remove(self::DERNIER_IMPORT);

        return $this->redirectToRoute('app_rencontre_index');
    }

    /** Efface les matchs d'une équipe pour une saison (calendrier importé par erreur…), sauf ceux déjà complétés. */
    #[Route('/matchs/effacer', name: 'app_rencontre_effacer', methods: ['POST'])]
    public function effacerSaison(Request $request, RencontreRepository $rencontres, CategorieRepository $categories, ConvocationRepository $convocations, EntityManagerInterface $em): Response
    {
        $donnees = $request->getPayload();
        $categorie = array_values(array_filter($this->categoriesVisibles($categories), fn (Categorie $c) => $c->getId() === $donnees->getInt('categorie')))[0] ?? null;
        $saison = $donnees->getString('saison');
        if (!$categorie || !preg_match('#^(\d{4})/\d{4}$#', $saison, $m) || !$this->isCsrfTokenValid('effacer-matchs', $donnees->getString('_token'))) {
            return $this->redirectToRoute('app_rencontre_index');
        }
        $liste = $rencontres->createQueryBuilder('r')
            ->andWhere('r.categorie = :c')->setParameter('c', $categorie)
            ->andWhere('r.date BETWEEN :debut AND :fin')
            ->setParameter('debut', new \DateTimeImmutable($m[1] . '-07-01'))
            ->setParameter('fin', new \DateTimeImmutable(((int) $m[1] + 1) . '-06-30'))
            ->getQuery()->getResult();
        $this->addFlash('succes', \sprintf('%s, saison %s : ', $categorie, $saison) . $this->effacer($liste, $convocations, $em));

        return $this->redirectToRoute('app_rencontre_index', ['categorie' => $categorie->getId(), 'saison' => $saison]);
    }

    /**
     * Supprime les matchs encore vierges ; garde ceux qui ont une feuille de match, des buts, des remplacements ou une convocation.
     *
     * @param list<Rencontre> $liste
     *
     * @return string bilan à afficher
     */
    private function effacer(array $liste, ConvocationRepository $convocations, EntityManagerInterface $em): string
    {
        $supprimes = $gardes = 0;
        foreach ($liste as $r) {
            if (!$this->isGranted(ActiviteVoter::GERER, $r)) {
                continue;
            }
            if (!$r->getParticipations()->isEmpty() || !$r->getButs()->isEmpty() || !$r->getRemplacements()->isEmpty() || $convocations->pour($r)) {
                ++$gardes;
                continue;
            }
            $em->remove($r);
            ++$supprimes;
        }
        $em->flush();

        return \sprintf('%d match%s supprimé%s.', $supprimes, $supprimes > 1 ? 's' : '', $supprimes > 1 ? 's' : '')
            . ($gardes ? \sprintf(' %d match%s déjà complété%s (feuille de match ou convocation) conservé%s.', $gardes, $gardes > 1 ? 's' : '', $gardes > 1 ? 's' : '', $gardes > 1 ? 's' : '') : '');
    }

    #[Route('/matchs/{id}', name: 'app_rencontre_voir', requirements: ['id' => '\d+'], methods: ['GET'])]
    #[IsGranted(ActiviteVoter::GERER, 'rencontre')]
    public function voir(Rencontre $rencontre, ConvocationRepository $convocations): Response
    {
        return $this->render('rencontre/voir.html.twig', ['rencontre' => $rencontre, 'convocation' => $convocations->pour($rencontre)]);
    }

    #[Route('/matchs/{id}/modifier', name: 'app_rencontre_modifier', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    #[IsGranted(ActiviteVoter::GERER, 'rencontre')]
    public function modifier(Request $request, Rencontre $rencontre, EntityManagerInterface $em, JoueurRepository $joueurs, ConvocationRepository $convocations): Response
    {
        // Feuille de match encore vide : on part des joueurs qui ont répondu présent à la convocation.
        $prerempli = 0;
        if ($request->isMethod('GET') && $rencontre->getParticipations()->isEmpty() && ($convocation = $convocations->pour($rencontre))) {
            foreach ($convocation->parReponse(Convoque::PRESENT) as $c) {
                $rencontre->addParticipation((new Participation())->setJoueur($c->getJoueur()));
                ++$prerempli;
            }
        }
        if ($prerempli) {
            $this->addFlash('info', \sprintf('Feuille de match préremplie avec les %d joueur%s présent%2$s à la convocation : complétez numéros et postes, puis enregistrez.', $prerempli, $prerempli > 1 ? 's' : ''));
        }

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
            'duree' => $rencontre->getCategorie()?->getDureeMatch() ?? 90,
        ]);
        $form->handleRequest($request);

        $nouveau = null === $rencontre->getId();
        if ($form->isSubmitted() && $form->isValid()) {
            $em->persist($rencontre);
            $em->flush();
            if ($nouveau && 'convocation' === $request->getPayload()->getString('suite')) {
                $this->addFlash('succes', 'Match enregistré. Choisissez maintenant les joueurs convoqués.');

                return $this->redirectToRoute('app_convocation', ['id' => $rencontre->getId()]);
            }
            $this->addFlash('succes', 'Match enregistré.');

            return $this->redirectToRoute('app_rencontre_voir', ['id' => $rencontre->getId()]);
        }

        return $this->render('rencontre/formulaire.html.twig', [
            'nouveau' => $nouveau,
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
