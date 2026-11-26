<?php

namespace App\Controller;

use App\Entity\Joueur;
use App\Entity\Presence;
use App\Entity\Rencontre;
use App\Entity\SemainePresence;
use App\Form\SemainePresenceType;
use App\Repository\CategorieRepository;
use App\Repository\CreneauEntrainementRepository;
use App\Repository\EntrainementRepository;
use App\Repository\JoueurRepository;
use App\Repository\SemainePresenceRepository;
use App\Security\ActiviteVoter;
use App\Security\JoueurVoter;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Feuilles de présence hebdomadaires aux entraînements.
 */
#[Route('/presences')]
class PresenceController extends AbstractController
{
    public function __construct(private readonly JoueurVoter $voter)
    {
    }

    #[Route('', name: 'app_presence_index', methods: ['GET'])]
    public function index(Request $request, SemainePresenceRepository $semaines, CategorieRepository $categories): Response
    {
        $secteurs = $this->voter->secteursModifiables();
        $saisons = $semaines->saisons();
        $visibles = $categories->findBy(['secteur' => $secteurs], ['ordre' => 'ASC']);
        $saison = $request->query->has('saison') ? $request->query->getString('saison') : $this->derniereSaison($semaines);
        $filtres = [
            'saison' => \in_array($saison, $saisons, true) ? $saison : null,
            // La catégorie mémorisée depuis un autre onglet peut ne pas être proposée ici.
            'categorie' => \in_array($request->query->getInt('categorie'), array_map(fn ($c) => $c->getId(), $visibles), true) ? $request->query->getInt('categorie') : null,
        ];
        $page = max(1, $request->query->getInt('page', 1));
        $resultats = $semaines->rechercher($secteurs, $filtres['saison'], $filtres['categorie'], $page);

        return $this->render('presence/index.html.twig', [
            'semaines' => $resultats,
            'total' => \count($resultats),
            'pages' => max(1, (int) ceil(\count($resultats) / SemainePresenceRepository::PAR_PAGE)),
            'page' => $page,
            'saisons' => $saisons,
            'categories' => $visibles,
            'filtres' => $filtres,
            'peut_creer' => [] !== $secteurs,
        ]);
    }

    #[Route('/nouvelle', name: 'app_presence_nouvelle', methods: ['GET', 'POST'])]
    public function nouvelle(Request $request, EntityManagerInterface $em, JoueurRepository $joueurs): Response
    {
        $secteurs = $this->voter->secteursModifiables();
        if (!$secteurs) {
            throw $this->createAccessDeniedException();
        }

        $lundi = new \DateTimeImmutable('monday this week');
        $semaine = (new SemainePresence())->setLundi($lundi);
        $form = $this->createForm(SemainePresenceType::class, $semaine, ['secteurs' => $secteurs]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            // La semaine commence toujours un lundi, même si une autre date a été choisie.
            $semaine->setLundi($semaine->getLundi()->modify('monday this week'));
            foreach ($joueurs->findBy(['categorie' => $semaine->getCategorie(), 'ancien' => false], ['nom' => 'ASC']) as $joueur) {
                $semaine->addPresence((new Presence())->setJoueur($joueur));
            }
            $em->persist($semaine);
            $em->flush();
            $this->addFlash('succes', 'Semaine créée avec les joueurs de la catégorie. Remplissez la grille puis enregistrez.');

            return $this->redirectToRoute('app_presence_voir', ['id' => $semaine->getId()]);
        }

        return $this->render('commun/formulaire.html.twig', [
            'form' => $form,
            'titre' => 'Nouvelle semaine de présence',
            'retour' => $this->generateUrl('app_presence_index'),
        ]);
    }

    #[Route('/{id}', name: 'app_presence_voir', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    #[IsGranted(ActiviteVoter::GERER, 'semaine')]
    public function voir(Request $request, SemainePresence $semaine, EntityManagerInterface $em, JoueurRepository $joueurs, CategorieRepository $categories,
        CreneauEntrainementRepository $creneauxEquipe, EntrainementRepository $entrainements): Response
    {
        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('presences' . $semaine->getId(), $request->getPayload()->getString('_token'))) {
                throw $this->createAccessDeniedException();
            }
            $saisie = $request->getPayload()->all('creneaux');
            // Note et forme de la semaine d'entraînement (avant les convocations).
            $notes = $request->getPayload()->all('note');
            $formes = $request->getPayload()->all('forme');
            foreach ($semaine->getPresences() as $presence) {
                $id = $presence->getJoueur()->getId();
                $presence->setCreneaux(array_map('strval', (array) ($saisie[$id] ?? [])));
                if (\array_key_exists($id, $notes) || \array_key_exists($id, $formes)) {
                    $presence->setNoteSemaine($notes[$id] ?? null)->setForme(\is_string($formes[$id] ?? null) ? $formes[$id] : null);
                    // Forme laissée vide : celle qui va avec la note.
                    if (null === $presence->getForme()) {
                        $presence->setForme(Presence::formeDeNote($presence->getNoteSemaine()));
                    }
                }
            }
            if ($ajout = $request->getPayload()->getInt('ajouter')) {
                $joueur = $joueurs->find($ajout);
                if ($joueur && !$this->estInscrit($semaine, $joueur)) {
                    $semaine->addPresence((new Presence())->setJoueur($joueur));
                }
            }
            $em->flush();
            $this->addFlash('succes', 'Présences enregistrées.');

            return $this->redirectToRoute('app_presence_voir', ['id' => $semaine->getId()]);
        }

        $presences = $semaine->getPresences()->toArray();
        usort($presences, fn (Presence $a, Presence $b) => [$a->getJoueur()->getNom(), $a->getJoueur()->getPrenom()] <=> [$b->getJoueur()->getNom(), $b->getJoueur()->getPrenom()]);

        return $this->render('presence/voir.html.twig', [
            'semaine' => $semaine,
            'presences' => $presences,
            'creneaux' => SemainePresence::CRENEAUX,
            'creneaux_entrainement' => $this->creneauxEntrainement($semaine, $presences, $creneauxEquipe, $entrainements),
            'seuils_forme' => ['top' => 7.5, 'bien' => 6, 'moyen' => 5],
            'formes' => Presence::FORMES,
            'motifs' => SemainePresence::MOTIFS,
            // Anciens codes (VA, AT) : affichés seulement là où ils sont déjà saisis.
            'motifs_anciens' => SemainePresence::MOTIFS_ANCIENS,
            'equipes' => array_map('strval', $categories->findBy([], ['ordre' => 'ASC'])),
            'a_ajouter' => array_filter(
                $joueurs->findBy(['categorie' => $semaine->getCategorie(), 'ancien' => false], ['nom' => 'ASC']),
                fn (Joueur $j) => !$this->estInscrit($semaine, $j)
            ),
        ]);
    }

    /**
     * Demi-journées où l'équipe s'entraîne cette semaine-là : ses créneaux habituels (planning), les séances prévues
     * cette semaine et les cases déjà remplies. Aucune connue : toutes (l'éducateur choisit).
     *
     * @param list<Presence> $presences
     *
     * @return list<string> clés de SemainePresence::CRENEAUX
     */
    private function creneauxEntrainement(SemainePresence $semaine, array $presences, CreneauEntrainementRepository $creneaux, EntrainementRepository $entrainements): array
    {
        $jours = [1 => 'lun', 2 => 'mar', 3 => 'mer', 4 => 'jeu', 5 => 'ven', 6 => 'sam'];
        $cle = fn (int $jour, string $heure) => isset($jours[$jour]) ? $jours[$jour] . ('sam' === $jours[$jour] || $heure < '12:00' ? '_m' : '_a') : null;
        $actifs = [];
        foreach ($creneaux->findBy(['categorie' => $semaine->getCategorie()]) as $c) {
            $actifs[] = $cle($c->getJour(), $c->getHeureDebut());
        }
        $lundi = $semaine->getLundi();
        $seances = $entrainements->createQueryBuilder('e')->andWhere('e.categorie = :c')->setParameter('c', $semaine->getCategorie())
            ->andWhere('e.date BETWEEN :l AND :d')->setParameter('l', $lundi)->setParameter('d', $lundi->modify('+6 days'))
            ->andWhere('e.annule = false AND e.supprime = false')->getQuery()->getResult();
        foreach ($seances as $e) {
            $actifs[] = $cle((int) $e->getDate()->format('N'), $e->getHeureDebut());
        }
        foreach ($presences as $p) {
            array_push($actifs, ...array_keys($p->getCreneaux()));
        }
        $actifs = array_values(array_intersect(array_keys(SemainePresence::CRENEAUX), array_filter($actifs)));

        return $actifs ?: array_keys(SemainePresence::CRENEAUX);
    }

    #[Route('/{id}/retirer/{presence}', name: 'app_presence_retirer', requirements: ['id' => '\d+', 'presence' => '\d+'], methods: ['POST'])]
    #[IsGranted(ActiviteVoter::GERER, 'semaine')]
    public function retirer(Request $request, SemainePresence $semaine, int $presence, EntityManagerInterface $em): Response
    {
        if ($this->isCsrfTokenValid('retirer' . $presence, $request->getPayload()->getString('_token'))) {
            foreach ($semaine->getPresences() as $p) {
                if ($p->getId() === $presence) {
                    $semaine->removePresence($p);
                }
            }
            $em->flush();
        }

        return $this->redirectToRoute('app_presence_voir', ['id' => $semaine->getId()]);
    }

    #[Route('/{id}/supprimer', name: 'app_presence_supprimer', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[IsGranted(ActiviteVoter::GERER, 'semaine')]
    public function supprimer(Request $request, SemainePresence $semaine, EntityManagerInterface $em): Response
    {
        if ($this->isCsrfTokenValid('supprimer-semaine' . $semaine->getId(), $request->getPayload()->getString('_token'))) {
            $em->remove($semaine);
            $em->flush();
            $this->addFlash('succes', 'Semaine supprimée.');
        }

        return $this->redirectToRoute('app_presence_index');
    }

    private function estInscrit(SemainePresence $semaine, Joueur $joueur): bool
    {
        return $semaine->getPresences()->exists(fn (int $i, Presence $p) => $p->getJoueur() === $joueur);
    }

    private function derniereSaison(SemainePresenceRepository $semaines): string
    {
        $fin = $semaines->createQueryBuilder('s')->select('MAX(s.lundi)')->getQuery()->getSingleScalarResult();

        return Rencontre::saisonDe(new \DateTimeImmutable($fin ?? 'now'));
    }
}
