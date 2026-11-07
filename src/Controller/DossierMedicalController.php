<?php

namespace App\Controller;

use App\Entity\Joueur;
use App\Form\RenseignementsMedicauxType;
use App\Repository\BlessureRepository;
use App\Repository\CategorieRepository;
use App\Repository\JoueurRepository;
use App\Repository\MesureRepository;
use App\Service\AccesMedical;
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
    public function __construct(private readonly AccesMedical $acces)
    {
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

        return $this->render('infirmerie/dossiers.html.twig', [
            'joueurs' => $requete->getQuery()->getResult(),
            'categories' => $categories->findBy([], ['ordre' => 'ASC', 'nom' => 'ASC']),
            'filtres' => ['q' => $q, 'categorie' => $categorie],
        ]);
    }

    #[Route('/{id}', name: 'app_dossier_medical', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function voir(Joueur $joueur, BlessureRepository $blessures, MesureRepository $mesures): Response
    {
        $this->verifier();

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

    /** Un droit médical en vigueur (Infirmerie ou Visite médicale) suffit pour ouvrir les dossiers. */
    private function verifier(): void
    {
        if (!$this->acces->aLeDroit()) {
            throw $this->createAccessDeniedException();
        }
    }
}
