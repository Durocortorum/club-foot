<?php

namespace App\Controller;

use App\Entity\Joueur;
use App\Form\RenseignementsMedicauxType;
use App\Repository\BlessureRepository;
use App\Repository\CategorieRepository;
use App\Repository\JoueurRepository;
use App\Repository\MesureRepository;
use App\Security\JoueurVoter;
use App\Service\AccesMedical;
use App\Service\AtlasAnatomique;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Dossiers médicaux : pour chaque joueur, identité, renseignements médicaux, blessures et visite médicale,
 * sans le reste de la fiche (matchs, présences, scolarité...). Seul accès aux joueurs des comptes
 * qui n'ont que du médical (médecin, kiné...). Ouvert par code e-mail (voir VerrouMedicalSubscriber).
 */
#[Route('/infirmerie/dossiers')]
class DossierMedicalController extends AbstractController
{
    public function __construct(
        private readonly AccesMedical $acces,
        private readonly JoueurVoter $voter,
    ) {
    }

    #[Route('', name: 'app_dossier_medical_index', methods: ['GET'])]
    public function index(Request $request, JoueurRepository $joueurs, CategorieRepository $categories): Response
    {
        $this->verifier();
        $q = trim($request->query->getString('q'));
        $categorie = $request->query->getInt('categorie') ?: null;

        $requete = $joueurs->createQueryBuilder('j')
            ->leftJoin('j.categorie', 'c')->addSelect('c')
            ->andWhere('j.ancien = false')
            ->orderBy('c.ordre', 'ASC')->addOrderBy('j.nom', 'ASC')->addOrderBy('j.prenom', 'ASC');
        if ('' !== $q) {
            $requete->andWhere('j.nom LIKE :q OR j.prenom LIKE :q')->setParameter('q', '%' . addcslashes($q, '%_') . '%');
        }
        if ($categorie) {
            $requete->andWhere('c.id = :categorie')->setParameter('categorie', $categorie);
        }
        // Éducateur : ses secteurs seulement ; médecin, compte médical seul, administrateur : tout le club.
        $secteurs = $this->voter->secteursMedicaux();
        if (null !== $secteurs) {
            $requete->andWhere('c.secteur IN (:secteurs)')->setParameter('secteurs', $secteurs ?: ['-']);
        }

        return $this->render('infirmerie/dossiers.html.twig', [
            'joueurs' => $requete->getQuery()->getResult(),
            'categories' => $categories->findBy(null === $secteurs ? [] : ['secteur' => $secteurs], ['ordre' => 'ASC', 'nom' => 'ASC']),
            'filtres' => ['q' => $q, 'categorie' => $categorie],
        ]);
    }

    #[Route('/{id}', name: 'app_dossier_medical', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function voir(Joueur $joueur, BlessureRepository $blessures, MesureRepository $mesures): Response
    {
        $this->verifier($joueur);

        return $this->render('infirmerie/dossier.html.twig', [
            'joueur' => $joueur,
            'blessures' => $this->isGranted('ROLE_INFIRMERIE_LECTURE') ? $blessures->findBy(['joueur' => $joueur], ['date' => 'DESC']) : [],
            'mesures' => $this->isGranted('ROLE_VISITE_MEDICALE_LECTURE') ? $mesures->findBy(['joueur' => $joueur], ['date' => 'DESC']) : [],
        ]);
    }

    #[IsGranted('ROLE_INFIRMERIE')]
    #[Route('/{id}/renseignements', name: 'app_dossier_medical_renseignements', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function renseignements(Request $request, Joueur $joueur, EntityManagerInterface $em): Response
    {
        $this->verifier($joueur);
        $form = $this->createForm(RenseignementsMedicauxType::class, $joueur);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $em->flush();
            $this->addFlash('succes', 'Renseignements médicaux enregistrés.');

            return $this->redirectToRoute('app_dossier_medical', ['id' => $joueur->getId()]);
        }

        return $this->render('commun/formulaire.html.twig', [
            'form' => $form,
            'titre' => 'Renseignements médicaux – ' . $joueur->getNomComplet(),
            'retour' => $this->generateUrl('app_dossier_medical', ['id' => $joueur->getId()]),
        ]);
    }

    /** Atlas anatomique, étape 1 : le corps entier (face ou dos), on clique sur la région touchée. */
    #[IsGranted('ROLE_INFIRMERIE')]
    #[Route('/{id}/localiser', name: 'app_dossier_medical_localiser', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function localiser(Request $request, Joueur $joueur, AtlasAnatomique $atlas): Response
    {
        $this->verifier($joueur);
        $vue = 'dos' === $request->query->getString('vue') ? 'dos' : 'face';
        $corps = AtlasAnatomique::CORPS[$vue];
        [$larg, $haut] = getimagesize($this->getParameter('kernel.project_dir') . '/public/images/atlas/' . $corps['image']) ?: [1, 1];

        // Liste des régions (accessible au clavier et sur petit écran), dans l'ordre du corps.
        $regions = [];
        foreach (AtlasAnatomique::REGIONS as $code => $r) {
            $regions[$code] = ['libelle' => $r['libelle'], 'cotes' => ($r['cote'] ?? false) ? ['droite', 'gauche'] : [null]];
        }

        return $this->render('infirmerie/atlas_corps.html.twig', [
            'joueur' => $joueur,
            'vue' => $vue,
            'corps' => $corps,
            'ratio' => $larg / $haut,
            'regions' => $regions,
            'libelles' => array_map(fn ($r) => $r['libelle'], AtlasAnatomique::REGIONS),
            'credits' => $atlas->credits([$corps['image']]),
            'date' => $this->dateSaisie($request),
        ]);
    }

    /**
     * Atlas anatomique, étape 2 : planches d'une région (muscles, os, ligaments) et liste des structures ;
     * le clic sur une structure ouvre « Nouvelle blessure » du joueur, déjà remplie.
     */
    #[IsGranted('ROLE_INFIRMERIE')]
    #[Route('/{id}/localiser/{region}/{cote}', name: 'app_dossier_medical_localiser_region', requirements: ['id' => '\d+', 'region' => '[a-z-]+', 'cote' => 'droite|gauche'], defaults: ['cote' => null], methods: ['GET'])]
    public function localiserRegion(Request $request, Joueur $joueur, string $region, ?string $cote, AtlasAnatomique $atlas): Response
    {
        $this->verifier($joueur);
        $r = AtlasAnatomique::REGIONS[$region] ?? throw $this->createNotFoundException();
        if (!($r['cote'] ?? false)) {
            $cote = null;
        }
        $n = $request->query->getInt('planche');
        $planche = $r['planches'][$n] ?? $r['planches'][$n = 0];
        $localisation = AtlasAnatomique::localisation($region, $cote);

        return $this->render('infirmerie/atlas_region.html.twig', [
            'joueur' => $joueur,
            'code' => $region,
            'region' => $r,
            'cote' => $cote,
            'localisation' => $localisation,
            'planche' => $planche,
            'n' => $n,
            'nouvelle' => array_filter(['joueur' => $joueur->getId(), 'date' => $this->dateSaisie($request), 'localisation' => $localisation, 'membre' => $r['membre']]),
            'date' => $this->dateSaisie($request),
            'vue' => \in_array($region, ['dos', 'lombaires', 'fessier'], true) ? 'dos' : 'face',
            'credits' => $atlas->credits($planche['images']),
        ]);
    }

    /** Date de la blessure déjà saisie dans le formulaire, transportée à travers l'atlas (AAAA-MM-JJ). */
    private function dateSaisie(Request $request): ?string
    {
        $date = $request->query->getString('date');

        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) && false !== \DateTimeImmutable::createFromFormat('!Y-m-d', $date) ? $date : null;
    }

    /**
     * Un droit médical en vigueur (Infirmerie ou Visite médicale) suffit pour ouvrir les dossiers,
     * dans son périmètre médical (ses secteurs pour un éducateur).
     */
    private function verifier(?Joueur $joueur = null): void
    {
        if (!$this->acces->aLeDroit() || ($joueur && !$this->voter->dansPerimetreMedical($joueur))) {
            throw $this->createAccessDeniedException();
        }
    }
}
