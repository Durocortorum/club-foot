<?php

namespace App\Controller;

use App\Entity\But;
use App\Entity\Categorie;
use App\Entity\Convoque;
use App\Entity\Joueur;
use App\Entity\Participation;
use App\Entity\Rencontre;
use App\Entity\Utilisateur;
use App\Form\RencontreType;
use App\Repository\ButRepository;
use App\Repository\CategorieRepository;
use App\Repository\ConvocationRepository;
use App\Repository\JoueurRepository;
use App\Repository\MessageRepository;
use App\Repository\ParticipationRepository;
use App\Repository\RencontreRepository;
use App\Repository\UtilisateurRepository;
use App\Security\ActiviteVoter;
use App\Service\Apparence;
use App\Service\BilanEquipe;
use App\Service\CompositionTerrain;
use App\Service\ImportMatchs;
use App\Service\ResponsablesMatchs;
use App\Service\StatsActions;
use App\Service\SynchroFeuille;
use Symfony\Component\Form\FormError;
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

    public function __construct(
        private readonly ActiviteVoter $voter,
        private readonly SynchroFeuille $synchro,
        private readonly CategorieRepository $categories,
        private readonly ResponsablesMatchs $responsables,
    ) {
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
            'peut_creer' => [] !== $this->categoriesTenables($categories),
            'responsables_equipe' => ($equipe = $filtres['categorie'] ? $categories->find($filtres['categorie']) : null) ? $this->responsables->de($equipe) : null,
            'peut_designer_equipe' => $equipe && $this->peutDesigner($equipe),
            'dernier_import' => ($i =$request->getSession()->get(self::DERNIER_IMPORT)) && $i['ids'] && time() - $i['le'] < self::DUREE_ANNULATION ? $i : null,
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
        StatsActions $statsActions,
    ): Response {
        $secteurs = $this->voter->secteursMatchs();
        $filtres = $this->filtres($request, $rencontres, $categories);
        $filtres['vue'] = \in_array($v = $request->query->getString('vue'), ['equipe', 'joueurs', 'actions', 'discipline'], true) ? $v : 'equipe';
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
        } elseif ('actions' === $filtres['vue']) {
            // Actions notées en direct, additionnées sur les matchs suivis ; en totaux ou ramenées à 90 minutes.
            $trisActions = [
                'total' => 'Nombre d\'actions', 'minutes' => 'Minutes', 'passes' => 'Passes réussies', 'pctPasses' => '% de passes réussies',
                'duels' => 'Duels gagnés', 'pctDuels' => '% de duels gagnés', 'controles' => 'Bons contrôles', 'pctControles' => '% de bons contrôles',
                'frappes' => 'Frappes et têtes', 'centres' => 'Centres', 'buts' => 'Buts', 'pd' => 'Passes décisives',
            ];
            $filtres['triActions'] = isset($trisActions[$t = $request->query->getString('tri')]) ? $t : 'total';
            $filtres['mode'] = 'par90' === $request->query->getString('mode') ? 'par90' : 'totaux';
            $actions = $statsActions->saison($rencontres->filtrer($rencontres->createQueryBuilder('r'), $secteurs, $s, $c)->getQuery()->getResult());
            usort($actions['joueurs'], fn (array $a, array $b) => [StatsActions::valeur($b, $filtres['triActions']), $b['minutes'], $a['nom']]
                <=> [StatsActions::valeur($a, $filtres['triActions']), $a['minutes'], $b['nom']]);
            $vue['filtres'] = $filtres;
            $vue += ['actions' => $actions, 'tris_actions' => $trisActions, 'minutes_mini' => StatsActions::MINUTES_MINI];
        } else {
            $cartons = $participations->cartons($secteurs, $s, $c);
            $vue += ['cartons' => $cartons, 'suivi' => $discipline->analyser($cartons, $rencontres->dernierMatch($secteurs, $s, $c))];
        }

        return $this->render('rencontre/statistiques.html.twig', $vue);
    }

    #[Route('/matchs/nouveau', name: 'app_rencontre_nouveau', methods: ['GET', 'POST'])]
    public function nouveau(Request $request, EntityManagerInterface $em, JoueurRepository $joueurs, Apparence $apparence, CategorieRepository $categories): Response
    {
        if ([] === $this->categoriesTenables($categories)) {
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
        $visibles = $this->categoriesTenables($categories);
        if ([] === $visibles) {
            throw $this->createAccessDeniedException();
        }
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
                // Page d'une poule collée ici : seuls les matchs du club sont gardés, les autres vont dans « Championnat ».
                $duClub = array_values(array_filter($analyse['matchs'], fn (array $m) => Rencontre::estLeClub($m['domicile']) || Rencontre::estLeClub($m['exterieur'])));
                if ($duClub && \count($duClub) < \count($analyse['matchs'])) {
                    $ignores = \count($analyse['matchs']) - \count($duClub);
                    $this->addFlash('info', \sprintf('%d %s entre d\'autres équipes %s : ils se mettent dans « Championnat » (matchs de la poule).', $ignores, $ignores > 1 ? 'matchs' : 'match', $ignores > 1 ? 'sont ignorés' : 'est ignoré'));
                    $analyse['matchs'] = array_map(fn (array $m) => ['aDomicile' => Rencontre::estLeClub($m['domicile']), 'adversaire' => $import->sansCategorie(Rencontre::estLeClub($m['domicile']) ? $m['exterieur'] : $m['domicile'])] + $m, $duClub);
                }
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
        $categorie = array_values(array_filter($this->categoriesTenables($categories), fn (Categorie $c) => $c->getId() === $donnees->getInt('categorie')))[0] ?? null;
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
            if (!$this->isGranted(ActiviteVoter::MODIFIER_MATCH, $r)) {
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
    public function voir(Rencontre $rencontre, ConvocationRepository $convocations, CompositionTerrain $terrain, MessageRepository $messages): Response
    {
        return $this->render('rencontre/voir.html.twig', [
            'rencontre' => $rencontre,
            'convocation' => $convocations->pour($rencontre),
            'compo' => $terrain->placer($rencontre),
            'comptes_rendus' => $messages->comptesRendus($rencontre),
            'modifiable' => $this->isGranted(ActiviteVoter::MODIFIER_MATCH, $rencontre),
            'peut_designer' => $rencontre->getCategorie() && $this->peutDesigner($rencontre->getCategorie()),
            'responsables' => $rencontre->getCategorie() ? $this->responsables->de($rencontre->getCategorie()) : null,
        ]);
    }

    /**
     * Schéma de la composition : titulaires placés sur le terrain (d'après le système et les postes),
     * que l'on déplace à la souris ou au doigt ; remplaçants sur le banc.
     */
    #[Route('/matchs/{id}/composition', name: 'app_rencontre_composition', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    #[IsGranted(ActiviteVoter::GERER, 'rencontre')]
    public function composition(Request $request, Rencontre $rencontre, CompositionTerrain $terrain, EntityManagerInterface $em): Response
    {
        $donnees = $request->getPayload();
        // Tout l'encadrement du secteur voit la composition ; seuls ceux qui tiennent les matchs de l'équipe la changent.
        $modifiable = $this->isGranted(ActiviteVoter::MODIFIER_MATCH, $rencontre);
        if ($request->isMethod('POST') && !$modifiable) {
            throw $this->createAccessDeniedException();
        }
        if ($request->isMethod('POST') && $this->isCsrfTokenValid('composition' . $rencontre->getId(), $donnees->getString('_token'))) {
            $systeme = $donnees->getString('systeme');
            if (\in_array($systeme, [Rencontre::SYSTEME_EDUCATEUR, ...array_merge(...array_values(Rencontre::SYSTEMES))], true)) {
                $rencontre->setSysteme($systeme);
            }
            if ('replacer' === $donnees->getString('action')) {
                $rencontre->setPlacements([]);
                $this->addFlash('succes', 'Joueurs replacés selon le système ' . ($rencontre->getSystemeLibelle() ?? 'par défaut') . '.');
            } else {
                $places = json_decode($donnees->getString('placements'), true);
                $rencontre->setPlacements(\is_array($places) ? $places : []);
                // Joueurs déplacés à la main : l'organisation devient « Placement par l'éducateur ».
                if ($rencontre->getPlacements()) {
                    $rencontre->setSysteme(Rencontre::SYSTEME_EDUCATEUR);
                }
                $this->addFlash('succes', 'Composition enregistrée.');
            }
            $this->tracer($rencontre);
            $em->flush();

            return $this->redirectToRoute('app_rencontre_composition', ['id' => $rencontre->getId()]);
        }

        return $this->render('rencontre/composition.html.twig', [
            'rencontre' => $rencontre,
            'compo' => $terrain->placer($rencontre),
            'systemes' => Rencontre::SYSTEMES,
            'modifiable' => $modifiable,
        ]);
    }

    #[Route('/matchs/{id}/modifier', name: 'app_rencontre_modifier', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    #[IsGranted(ActiviteVoter::MODIFIER_MATCH, 'rencontre')]
    public function modifier(Request $request, Rencontre $rencontre, EntityManagerInterface $em, JoueurRepository $joueurs, ConvocationRepository $convocations): Response
    {
        // Feuille de match encore vide : on part des joueurs qui ont répondu présent à la convocation.
        $prerempli = 0;
        if ($request->isMethod('GET') && $rencontre->getParticipations()->isEmpty() && ($convocation = $convocations->pour($rencontre))) {
            foreach ($convocation->parReponse(Convoque::PRESENT) as $c) {
                // Numéro donné sur la tablette (match en direct), sinon le premier libre : 1, 2, 3…
                $rencontre->ajouterAFeuille($c->getJoueur());
                ++$prerempli;
            }
        }
        if ($prerempli) {
            $this->addFlash('info', \sprintf('Feuille de match préremplie avec les %d joueur%s présent%2$s à la convocation : complétez numéros et postes, puis enregistrez.', $prerempli, $prerempli > 1 ? 's' : ''));
        }

        // Joueurs proposés sur la feuille : les convoqués, sauf ceux qui ont répondu absent.
        $convocation = $convocations->pour($rencontre);
        $convoques = $convocation ? array_values(array_map(fn ($c) => $c->getJoueur()->getId(), array_filter($convocation->getConvoques(), fn ($c) => Convoque::ABSENT !== $c->getReponse()))) : null;

        return $this->formulaire($request, $rencontre, $em, $joueurs, 'Modifier le match ' . $rencontre, $convoques);
    }

    #[Route('/matchs/{id}/supprimer', name: 'app_rencontre_supprimer', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[IsGranted(ActiviteVoter::MODIFIER_MATCH, 'rencontre')]
    public function supprimer(Request $request, Rencontre $rencontre, EntityManagerInterface $em): Response
    {
        if ($this->isCsrfTokenValid('supprimer-match' . $rencontre->getId(), $request->getPayload()->getString('_token'))) {
            $em->remove($rencontre);
            $em->flush();
            $this->addFlash('succes', 'Match supprimé.');
        }

        return $this->redirectToRoute('app_rencontre_index');
    }

    /** @param list<int>|null $convoques joueurs convoqués (hors absents) proposés sur la feuille ; null : pas de convocation */
    private function formulaire(Request $request, Rencontre $rencontre, EntityManagerInterface $em, JoueurRepository $joueurs, string $titre, ?array $convoques = null): Response
    {
        $secteurs = $this->voter->secteursMatchs() ?? array_keys(Categorie::SECTEURS);
        // Équipes proposées : celles dont ce compte tient les matchs (plus celle du match, déjà vérifiée).
        $tenables = array_map(fn (Categorie $c) => $c->getId(), $this->categoriesTenables($this->categories));
        if ($rencontre->getCategorie()) {
            $tenables[] = $rencontre->getCategorie()->getId();
        }
        $form = $this->createForm(RencontreType::class, $rencontre, [
            'secteurs' => $secteurs,
            'categories' => array_values(array_unique($tenables)),
            'joueurs' => $this->joueursSelectionnables($rencontre, $secteurs, $joueurs),
            'duree' => $rencontre->getCategorie()?->getDureeMatch() ?? 90,
            'convoques' => $convoques,
        ]);
        $nouveau = null === $rencontre->getId();
        // Match suivi en direct : les tablettes peuvent changer la feuille (buts, cartons, changements) pendant
        // qu'elle est ouverte ici. Si c'est le cas, on n'écrase rien : la feuille est rouverte à jour.
        $photo = $nouveau ? null : SynchroFeuille::photoFeuille($rencontre);
        $etat = $nouveau ? '' : SynchroFeuille::empreinte($rencontre);
        if ($request->isMethod('POST') && !$nouveau && $request->request->has('etat_feuille')
            && $request->request->getString('etat_feuille') !== $etat && $this->synchro->suiviEnDirect($rencontre)) {
            $this->addFlash('erreur', 'Pendant que vous modifiiez la feuille, des buts, cartons ou changements ont été notés en direct. Rien n\'a été enregistré pour ne pas les effacer : la feuille est rouverte à jour, refaites vos modifications.');

            return $this->redirectToRoute('app_rencontre_modifier', ['id' => $rencontre->getId()]);
        }
        $titulairesAvant = $rencontre->nombreTitulaires();
        $doublonsAvant = $rencontre->nombreNumerosEnDouble();
        $systemeAvant = $rencontre->getSysteme();
        $form->handleRequest($request);
        // Un joueur entré en cours de match n'est pas titulaire (case décochée d'office).
        $corriges = $form->isSubmitted() ? $rencontre->decocherTitulairesEntres() : [];
        // Jamais plus de titulaires que de joueurs sur le terrain (on ne peut pas en ajouter au-delà),
        // ni deux joueurs avec le même numéro (les anciennes feuilles importées restent enregistrables).
        if ($form->isSubmitted()) {
            foreach ([$rencontre->erreurTitulaires($titulairesAvant), $rencontre->erreurNumeros($doublonsAvant), $rencontre->erreurJoueursEnDouble()] as $erreur) {
                if ($erreur) {
                    $form->addError(new FormError($erreur));
                }
            }
        }

        if ($form->isSubmitted() && $form->isValid()) {
            // Organisation de jeu changée : les places choisies à la main sont oubliées, les joueurs se placent
            // selon la nouvelle organisation (comme « Replacer selon le système » de la composition).
            // (« Placement par l'éducateur » choisi : les places à la main sont gardées.)
            $replaces = $systemeAvant !== $rencontre->getSysteme() && $rencontre->getPlacements() && !$rencontre->isPlacementEducateur();
            if ($replaces) {
                $rencontre->setPlacements([]);
            }
            $this->tracer($rencontre);
            $em->persist($rencontre);
            // Match suivi en direct : ce qui a changé sur la feuille l'est aussi dans le direct (tablettes, bilan).
            if ($photo) {
                $this->synchro->versDirect($rencontre, $photo);
            }
            $em->flush();
            if ($nouveau && 'convocation' === $request->getPayload()->getString('suite')) {
                $this->addFlash('succes', 'Match enregistré. Choisissez maintenant les joueurs convoqués.');

                return $this->redirectToRoute('app_convocation', ['id' => $rencontre->getId()]);
            }
            $this->addFlash('succes', 'Match enregistré.');
            if ($replaces) {
                $this->addFlash('info', \sprintf('Organisation %s : joueurs replacés sur le terrain selon cette organisation (« Placer les joueurs » pour les déplacer).', $rencontre->getSystemeLibelle() ?: 'retirée'));
            }
            if ($corriges) {
                $this->addFlash('info', 'Entré' . (\count($corriges) > 1 ? 's' : '') . ' en cours de match, donc pas titulaire' . (\count($corriges) > 1 ? 's' : '') . ' : ' . implode(', ', $corriges) . '.');
            }

            return $this->redirectToRoute('app_rencontre_voir', ['id' => $rencontre->getId()]);
        }

        return $this->render('rencontre/formulaire.html.twig', [
            'nouveau' => $nouveau,
            'rencontre' => $rencontre,
            'form' => $form,
            'titre' => $titre,
            // État de la feuille à l'ouverture : comparé à l'enregistrement (changée entre-temps par une tablette ?).
            'etat_feuille' => $etat,
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

    /**
     * Qui tient les matchs de l'équipe : l'administrateur choisit l'éducateur responsable ; le responsable
     * (ou l'administrateur) désigne les personnes qui peuvent aussi modifier les rapports de match.
     */
    #[Route('/matchs/equipe/{id}/responsables', name: 'app_rencontre_responsables', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function responsables(Request $request, Categorie $categorie, UtilisateurRepository $utilisateurs, EntityManagerInterface $em): Response
    {
        if (!$this->peutDesigner($categorie)) {
            throw $this->createAccessDeniedException();
        }
        $candidats = $this->candidats($categorie, $utilisateurs);
        $parId = [];
        foreach ($candidats as $u) {
            $parId[$u->getId()] = $u;
        }
        // L'administrateur et le responsable du secteur choisissent aussi l'éducateur responsable de l'équipe.
        $admin = $this->isGranted('ROLE_ADMIN') || $this->responsables->estResponsableSecteur($categorie, $this->getUser() instanceof Utilisateur ? $this->getUser() : null);
        $p = $request->getPayload();
        if ($request->isMethod('POST') && $this->isCsrfTokenValid('responsables' . $categorie->getId(), $p->getString('_token'))) {
            if ($admin) {
                $categorie->setResponsableMatchs($parId[$p->getInt('responsable')] ?? null);
            }
            $choisis = array_map('intval', $p->all('redacteurs'));
            foreach ($categorie->getRedacteursMatchs()->toArray() as $u) {
                if (!\in_array($u->getId(), $choisis, true)) {
                    $categorie->removeRedacteurMatchs($u);
                }
            }
            foreach ($choisis as $id) {
                if (isset($parId[$id])) {
                    $categorie->addRedacteurMatchs($parId[$id]);
                }
            }
            $em->flush();
            $this->responsables->reset();
            $comptes = $this->responsables->de($categorie)['comptes'];
            $this->addFlash('succes', $comptes
                ? \sprintf('%s : seuls %s (et l\'administrateur) peuvent maintenant modifier les matchs et les convocations.', $categorie, $this->noms($categorie))
                : \sprintf('%s : pas d\'éducateur responsable avec un compte, tout l\'encadrement du secteur peut modifier les matchs.', $categorie));

            return $this->redirect($this->retourLocal($p->getString('retour'), $categorie));
        }

        return $this->render('rencontre/responsables.html.twig', [
            'categorie' => $categorie,
            'candidats' => $candidats,
            'admin' => $admin,
            'responsables' => $this->responsables->de($categorie),
            'educateurs' => $this->responsables->educateursDe($categorie),
            'retour' => $this->retourLocal($request->query->getString('retour'), $categorie),
        ]);
    }

    /** Page où revenir : une adresse du site seulement (jamais un autre site), sinon la liste des matchs de l'équipe. */
    private function retourLocal(string $retour, Categorie $categorie): string
    {
        return str_starts_with($retour, '/') && !str_starts_with($retour, '//') && !str_contains($retour, '\\')
            ? $retour
            : $this->generateUrl('app_rencontre_index', ['categorie' => $categorie->getId()]);
    }

    /** Trace de la dernière modification du rapport de match : qui et quand. */
    private function tracer(Rencontre $rencontre): void
    {
        if (($moi = $this->getUser()) instanceof Utilisateur) {
            $rencontre->marquerModifie($moi);
        }
    }

    /** Peut choisir qui tient les matchs de l'équipe : l'administrateur, ou l'éducateur responsable lui-même. */
    private function peutDesigner(Categorie $categorie): bool
    {
        $moi = $this->getUser();

        return $this->isGranted('ROLE_ADMIN') || ($moi instanceof Utilisateur
            && ($this->responsables->estResponsable($categorie, $moi) || $this->responsables->estResponsableSecteur($categorie, $moi)));
    }

    /** Prénoms et noms des responsables et des personnes désignées, pour le message de confirmation. */
    private function noms(Categorie $categorie): string
    {
        $noms = array_map(fn (Utilisateur $u) => trim($u->getPrenom() . ' ' . $u->getNom()), [...$this->responsables->de($categorie)['comptes'], ...$categorie->getRedacteursMatchs()->toArray()]);

        return 1 === \count($noms) ? $noms[0] : implode(', ', \array_slice($noms, 0, -1)) . ' et ' . end($noms);
    }

    /**
     * Comptes de l'encadrement qui voient les matchs de l'équipe (droit du secteur, « Rapports de match »
     * ou administrateur) : on choisit parmi eux le responsable et les personnes désignées.
     *
     * @return list<Utilisateur>
     */
    private function candidats(Categorie $categorie, UtilisateurRepository $utilisateurs): array
    {
        $role = Categorie::SECTEURS[$categorie->getSecteur()]['role'] ?? null;

        return array_values(array_filter(
            $utilisateurs->findBy([], ['prenom' => 'ASC', 'nom' => 'ASC']),
            fn (Utilisateur $u) => \in_array('ROLE_ENCADREMENT', $u->getRoles(), true)
                && [] !== array_intersect(array_filter([$role, 'ROLE_RAPPORT_MATCH', 'ROLE_ADMIN']), $u->getRoles())
        ));
    }

    /**
     * Équipes dont ce compte peut créer, importer et modifier les matchs : celles de ses secteurs (ou toutes avec
     * « Rapports de match »), sauf celles qui ont un éducateur responsable dont il n'est pas, ni désigné.
     *
     * @return list<Categorie>
     */
    private function categoriesTenables(CategorieRepository $categories): array
    {
        return array_values(array_filter($this->categoriesVisibles($categories), fn (Categorie $c) => $this->isGranted(ActiviteVoter::MODIFIER_MATCH, $c)));
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
