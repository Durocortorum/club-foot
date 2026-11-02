<?php

namespace App\Controller;

use App\Entity\Categorie;
use App\Entity\CoachSaison;
use App\Entity\Educateur;
use App\Repository\CategorieRepository;
use App\Repository\CoachSaisonRepository;
use App\Repository\EducateurRepository;
use App\Repository\JoueurRepository;
use App\Repository\ParticipationRepository;
use App\Repository\PresenceRepository;
use App\Repository\RencontreRepository;
use App\Repository\SemainePresenceRepository;
use App\Security\ActiviteVoter;
use App\Security\JoueurVoter;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Écran « Archives » d'Access : l'effectif de chaque équipe, saison par saison.
 * Access n'enregistrait pas les effectifs : ils sont reconstitués à partir des feuilles
 * de match et des feuilles de présence de la catégorie sur la saison.
 */
#[Route('/archives')]
class ArchivesController extends AbstractController
{
    public function __construct(
        private readonly ActiviteVoter $activite,
        private readonly JoueurVoter $joueurs,
        private readonly RencontreRepository $rencontres,
        private readonly SemainePresenceRepository $semaines,
        private readonly CoachSaisonRepository $coachs,
    ) {
    }

    #[Route('', name: 'app_archives', methods: ['GET'])]
    public function index(CategorieRepository $categories): Response
    {
        $secteurs = $this->activite->secteursMatchs();
        $visibles = null === $secteurs
            ? $categories->findBy([], ['ordre' => 'ASC'])
            : $categories->findBy(['secteur' => $secteurs], ['ordre' => 'ASC']);
        $saisons = $this->saisonsParCategorie();

        $parSecteur = [];
        foreach ($visibles as $c) {
            if (isset($saisons[$c->getId()])) {
                $parSecteur[$c->getSecteur()][] = ['categorie' => $c, 'saisons' => $saisons[$c->getId()]];
            }
        }

        return $this->render('archives/index.html.twig', [
            'secteurs' => array_intersect_key(Categorie::SECTEURS, $parSecteur),
            'categories' => $parSecteur,
        ]);
    }

    #[Route('/{id}/{saison}', name: 'app_archives_saison', requirements: ['id' => '\d+', 'saison' => '\d{4}-\d{4}'], methods: ['GET'])]
    public function saison(
        Categorie $categorie,
        string $saison,
        ParticipationRepository $participations,
        PresenceRepository $presences,
        EducateurRepository $educateurs,
        JoueurRepository $joueurs,
    ): Response {
        $secteurs = $this->activite->secteursMatchs();
        if (null !== $secteurs && !\in_array($categorie->getSecteur(), $secteurs, true)) {
            throw $this->createAccessDeniedException();
        }
        $saison = str_replace('-', '/', $saison);
        $voitPresences = $this->voitPresences($categorie);

        $stats = $participations->statistiques(null, $saison, $categorie->getId());
        $effectif = [];
        foreach ($stats['joueurs'] as $j) {
            $effectif[$j['id']] = ['id' => $j['id'], 'nom' => $j['nom'], 'prenom' => $j['prenom'], 'poste' => $j['posteHabituel'], 'match' => $j, 'presence' => null];
        }
        foreach ($voitPresences ? $presences->bilanEquipe($categorie, $saison) : [] as $id => $p) {
            $effectif[$id] ??= ['id' => $id, 'nom' => $p['nom'], 'prenom' => $p['prenom'], 'poste' => $p['poste'], 'match' => null];
            $effectif[$id]['presence'] = $p;
        }
        usort($effectif, fn (array $a, array $b) => [$a['nom'], $a['prenom']] <=> [$b['nom'], $b['prenom']]);

        $coachs = $this->coachs->pourEquipe($categorie->getId(), $saison);
        $dejaCoachs = array_map(fn (CoachSaison $c) => $c->getEducateur(), $coachs);

        return $this->render('archives/saison.html.twig', [
            'categorie' => $categorie,
            'saison' => $saison,
            'saisons' => $this->saisonsParCategorie()[$categorie->getId()] ?? [$saison],
            'effectif' => $effectif,
            'bilan' => $this->rencontres->bilan(null, $saison, $categorie->getId()),
            'voit_presences' => $voitPresences,
            'coachs' => $coachs,
            'peut_modifier' => $voitPresences,
            'educateurs' => $voitPresences
                ? array_filter($educateurs->findBy([], ['nom' => 'ASC', 'prenom' => 'ASC']), fn (Educateur $e) => !\in_array($e, $dejaCoachs, true))
                : [],
            // Pistes pour la saisie : l'éducateur noté sur la fiche de chaque joueur (souvent celui
            // de sa dernière équipe, pas forcément celui de cette saison).
            'suggestions' => $voitPresences
                ? array_filter($joueurs->educateursDe(array_column($effectif, 'id')), fn (array $s) => !\in_array($s['educateur'], $dejaCoachs, true))
                : [],
        ]);
    }

    #[Route('/{id}/{saison}/coach', name: 'app_archives_coach_ajouter', requirements: ['id' => '\d+', 'saison' => '\d{4}-\d{4}'], methods: ['POST'])]
    public function ajouterCoach(Request $request, Categorie $categorie, string $saison, EducateurRepository $educateurs, EntityManagerInterface $em): Response
    {
        if (!$this->voitPresences($categorie)) {
            throw $this->createAccessDeniedException();
        }
        $educateur = $educateurs->find($request->getPayload()->getInt('educateur'));
        $libelle = str_replace('-', '/', $saison);
        $deja = array_filter($this->coachs->pourEquipe($categorie->getId(), $libelle), fn (CoachSaison $c) => $c->getEducateur() === $educateur);
        if ($educateur && !$deja && $this->isCsrfTokenValid('coach-' . $categorie->getId() . $saison, $request->getPayload()->getString('_token'))) {
            $em->persist(new CoachSaison($categorie, $libelle, $educateur));
            $em->flush();
            $this->addFlash('succes', $educateur->getNomComplet() . ' ajouté comme coach.');
        }

        return $this->redirectToRoute('app_archives_saison', ['id' => $categorie->getId(), 'saison' => $saison]);
    }

    #[Route('/coach/{id}/retirer', name: 'app_archives_coach_retirer', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function retirerCoach(Request $request, CoachSaison $coach, EntityManagerInterface $em): Response
    {
        $categorie = $coach->getCategorie();
        if (!$this->voitPresences($categorie)) {
            throw $this->createAccessDeniedException();
        }
        if ($this->isCsrfTokenValid('retirer-coach' . $coach->getId(), $request->getPayload()->getString('_token'))) {
            $em->remove($coach);
            $em->flush();
            $this->addFlash('succes', 'Coach retiré.');
        }

        return $this->redirectToRoute('app_archives_saison', ['id' => $categorie->getId(), 'saison' => str_replace('/', '-', $coach->getSaison())]);
    }

    /**
     * Saisons où chaque catégorie a des matchs ou, si l'utilisateur peut les voir, des feuilles de présence.
     *
     * @return array<int, list<string>>
     */
    private function saisonsParCategorie(): array
    {
        $saisons = $this->rencontres->saisonsParCategorie($this->activite->secteursMatchs());
        foreach ($this->semaines->saisonsParCategorie($this->joueurs->secteursModifiables()) as $id => $liste) {
            $saisons[$id] = array_values(array_unique(array_merge($saisons[$id] ?? [], $liste)));
            sort($saisons[$id]);
        }

        return $saisons;
    }

    private function voitPresences(Categorie $categorie): bool
    {
        return \in_array($categorie->getSecteur(), $this->joueurs->secteursModifiables(), true);
    }
}
