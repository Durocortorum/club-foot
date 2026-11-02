<?php

namespace App\Controller;

use App\Entity\Joueur;
use App\Form\JoueurType;
use App\Repository\BlessureRepository;
use App\Repository\BulletinScolaireRepository;
use App\Repository\CategorieRepository;
use App\Repository\CoachSaisonRepository;
use App\Repository\DetectionRepository;
use App\Repository\JoueurRepository;
use App\Repository\MesureRepository;
use App\Repository\ParticipationRepository;
use App\Repository\PresenceRepository;
use App\Repository\RencontreRepository;
use App\Repository\SanctionRepository;
use App\Repository\SemainePresenceRepository;
use App\Repository\TestPhysiqueRepository;
use App\Security\JoueurVoter;
use App\Service\BilanJoueur;
use App\Service\Photos;
use Symfony\Component\Form\FormInterface;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/joueurs')]
class JoueurController extends AbstractController
{
    public function __construct(
        private readonly JoueurVoter $voter,
        private readonly Photos $photos,
    ) {
    }

    #[Route('', name: 'app_joueur_index', methods: ['GET'])]
    public function index(
        Request $request,
        JoueurRepository $joueurs,
        CategorieRepository $categories,
        RencontreRepository $rencontres,
        SemainePresenceRepository $semaines,
        CoachSaisonRepository $coachs,
    ): Response {
        $secteurs = $this->voter->secteursVisibles();
        $texte = trim($request->query->getString('q')) ?: null;
        $visibles = $categories->findBy(['secteur' => $secteurs], ['ordre' => 'ASC']);
        // La catégorie mémorisée depuis un autre onglet peut ne pas être proposée ici.
        $categorieId = \in_array($request->query->getInt('categorie'), array_map(fn ($c) => $c->getId(), $visibles), true) ? $request->query->getInt('categorie') : null;
        // Saisons où des feuilles de match ou de présence existent, de la plus récente à la plus ancienne.
        $saisons = array_unique(array_merge(
            ...array_values($rencontres->saisonsParCategorie($secteurs)),
            ...array_values($semaines->saisonsParCategorie($secteurs)),
        ));
        rsort($saisons);
        $saison = \in_array($s = $request->query->getString('saison'), $saisons, true) ? $s : null;
        $statut = \in_array($s = $request->query->getString('statut', 'actifs'), ['actifs', 'anciens', 'tous'], true) ? $s : 'actifs';
        $page = max(1, $request->query->getInt('page', 1));

        $resultats = $joueurs->rechercher($secteurs, $texte, $categorieId, $statut, $page, $saison);

        return $this->render('joueur/index.html.twig', [
            'joueurs' => $resultats,
            'total' => \count($resultats),
            'pages' => max(1, (int) ceil(\count($resultats) / JoueurRepository::PAR_PAGE)),
            'page' => $page,
            'categories' => $visibles,
            'saisons' => $saisons,
            // Le coach d'une équipe : pour une saison, celui saisi dans les archives ; sinon l'éducateur
            // indiqué sur les fiches des joueurs (ce champ ne donne que l'éducateur actuel).
            'educateurs' => match (true) {
                !$categorieId => [],
                null !== $saison => array_map(fn ($c) => ['educateur' => $c->getEducateur(), 'joueurs' => null], $coachs->pourEquipe($categorieId, $saison)),
                default => $joueurs->educateurs($secteurs, $texte, $categorieId, $statut),
            },
            'filtres' => ['q' => $texte, 'categorie' => $categorieId, 'statut' => $statut, 'saison' => $saison],
            'peut_creer' => [] !== $this->voter->secteursModifiables(),
        ]);
    }

    #[Route('/nouveau', name: 'app_joueur_nouveau', methods: ['GET', 'POST'])]
    public function nouveau(Request $request, EntityManagerInterface $em): Response
    {
        $secteurs = $this->voter->secteursModifiables();
        if (!$secteurs) {
            throw $this->createAccessDeniedException();
        }

        $joueur = new Joueur();
        $form = $this->createForm(JoueurType::class, $joueur, [
            'secteurs' => $secteurs,
            'avec_medical' => $this->isGranted('ROLE_INFIRMERIE'),
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->enregistrerPhoto($form, $joueur);
            $em->persist($joueur);
            $em->flush();
            $this->addFlash('succes', 'Joueur créé.');

            return $this->redirectToRoute('app_joueur_voir', ['id' => $joueur->getId()]);
        }

        return $this->render('joueur/formulaire.html.twig', [
            'joueur' => $joueur,
            'form' => $form,
            'titre' => 'Nouveau joueur',
        ]);
    }

    #[Route('/{id}', name: 'app_joueur_voir', requirements: ['id' => '\d+'], methods: ['GET'])]
    #[IsGranted(JoueurVoter::VOIR, 'joueur')]
    public function voir(
        Joueur $joueur,
        ParticipationRepository $participations,
        PresenceRepository $presences,
        BlessureRepository $blessures,
        MesureRepository $mesures,
        TestPhysiqueRepository $tests,
        BulletinScolaireRepository $bulletins,
        SanctionRepository $sanctions,
        DetectionRepository $detections,
        BilanJoueur $bilan,
    ): Response {
        $matchs = $participations->parJoueur($joueur);
        $scolaire = $this->isGranted('ROLE_SCOLAIRE');

        return $this->render('joueur/voir.html.twig', [
            'joueur' => $joueur,
            'matchs' => \array_slice($matchs, 0, 10),
            'bilan' => $bilan->parSaison($joueur, $matchs),
            'presences' => $presences->bilanParSaison($joueur),
            'blessures' => $this->isGranted('ROLE_INFIRMERIE') ? $blessures->findBy(['joueur' => $joueur], ['date' => 'DESC']) : [],
            'mesures' => $mesures->findBy(['joueur' => $joueur], ['date' => 'DESC']),
            'tests' => $tests->findBy(['joueur' => $joueur], ['date' => 'DESC']),
            'bulletins' => $scolaire ? $bulletins->findBy(['joueur' => $joueur], ['saison' => 'DESC', 'id' => 'DESC']) : [],
            // Les sanctions sont utiles aux éducateurs du joueur (suspensions), pas seulement au suivi scolaire.
            'sanctions' => $scolaire || $this->isGranted(JoueurVoter::MODIFIER, $joueur) ? $sanctions->findBy(['joueur' => $joueur], ['date' => 'DESC']) : [],
            'detection' => $detections->findOneBy(['joueur' => $joueur]),
        ]);
    }

    #[Route('/{id}/modifier', name: 'app_joueur_modifier', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    #[IsGranted(JoueurVoter::MODIFIER, 'joueur')]
    public function modifier(Request $request, Joueur $joueur, EntityManagerInterface $em): Response
    {
        $form = $this->createForm(JoueurType::class, $joueur, [
            'secteurs' => $this->voter->secteursModifiables(),
            'avec_medical' => $this->isGranted('ROLE_INFIRMERIE'),
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->enregistrerPhoto($form, $joueur);
            $em->flush();
            $this->addFlash('succes', 'Fiche enregistrée.');

            return $this->redirectToRoute('app_joueur_voir', ['id' => $joueur->getId()]);
        }

        return $this->render('joueur/formulaire.html.twig', [
            'joueur' => $joueur,
            'form' => $form,
            'titre' => 'Modifier ' . $joueur->getNomComplet(),
        ]);
    }

    #[Route('/{id}/supprimer', name: 'app_joueur_supprimer', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function supprimer(Request $request, Joueur $joueur, EntityManagerInterface $em): Response
    {
        if ($this->isCsrfTokenValid('supprimer' . $joueur->getId(), $request->getPayload()->getString('_token'))) {
            $photo = $joueur->getPhoto();
            $em->remove($joueur);
            $em->flush();
            $this->photos->supprimer($photo);
            $this->addFlash('succes', 'Joueur supprimé.');
        }

        return $this->redirectToRoute('app_joueur_index');
    }

    private function enregistrerPhoto(FormInterface $form, Joueur $joueur): void
    {
        if ($fichier = $form->get('photo')->getData()) {
            $joueur->setPhoto($this->photos->enregistrer($fichier, 'joueur', $joueur->getPhoto()));
        }
    }
}
