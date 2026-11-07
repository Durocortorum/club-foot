<?php

namespace App\Controller;

use App\Entity\BulletinScolaire;
use App\Entity\Sanction;
use App\Form\BulletinScolaireType;
use App\Form\SanctionType;
use App\Repository\BulletinScolaireRepository;
use App\Repository\CategorieRepository;
use App\Repository\JoueurRepository;
use App\Repository\SanctionRepository;
use App\Service\Documents;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Suivi scolaire : bulletins de notes et discipline. Réservé au droit « Scolaire ».
 */
#[Route('/scolarite')]
#[IsGranted('ROLE_SCOLAIRE_LECTURE')]
class ScolariteController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly Documents $documents,
    ) {
    }

    #[Route('', name: 'app_scolarite_index', methods: ['GET'])]
    public function index(Request $request, BulletinScolaireRepository $bulletins, CategorieRepository $categories): Response
    {
        $filtres = [
            'q' => trim($request->query->getString('q')) ?: null,
            'categorie' => $request->query->getInt('categorie') ?: null,
            'pdf' => \in_array($p = $request->query->getString('pdf'), ['avec', 'sans'], true) ? $p : null,
        ];
        $qb = $bulletins->createQueryBuilder('b')
            ->addSelect('j', 'c')
            ->join('b.joueur', 'j')
            ->leftJoin('j.categorie', 'c')
            ->orderBy('j.nom')->addOrderBy('j.prenom')->addOrderBy('b.saison', 'DESC');
        if ($filtres['q']) {
            $qb->andWhere('j.nom LIKE :t OR j.prenom LIKE :t OR b.libelle LIKE :t')->setParameter('t', '%' . $filtres['q'] . '%');
        }
        if ($filtres['categorie']) {
            $qb->andWhere('c.id = :cat')->setParameter('cat', $filtres['categorie']);
        }
        if ($filtres['pdf']) {
            $qb->andWhere('avec' === $filtres['pdf'] ? 'b.fichier IS NOT NULL' : 'b.fichier IS NULL');
        }

        return $this->render('scolarite/index.html.twig', [
            'bulletins' => $qb->getQuery()->getResult(),
            'filtres' => $filtres,
            'categories' => $categories->findBy([], ['ordre' => 'ASC']),
        ]);
    }

    #[IsGranted('ROLE_SCOLAIRE')]
    #[Route('/bulletins/nouveau', name: 'app_bulletin_nouveau', methods: ['GET', 'POST'])]
    public function nouveauBulletin(Request $request, JoueurRepository $joueurs): Response
    {
        $bulletin = new BulletinScolaire();
        $joueur = $request->query->getInt('joueur') ? $joueurs->find($request->query->getInt('joueur')) : null;
        $bulletin->setJoueur($joueur);

        return $this->formulaireBulletin($request, $bulletin, 'Nouveau bulletin', null !== $joueur);
    }

    #[IsGranted('ROLE_SCOLAIRE')]
    #[Route('/bulletins/{id}/modifier', name: 'app_bulletin_modifier', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function modifierBulletin(Request $request, BulletinScolaire $bulletin): Response
    {
        return $this->formulaireBulletin($request, $bulletin, 'Bulletin de ' . $bulletin->getJoueur()->getNomComplet(), true);
    }

    #[Route('/bulletins/{id}/pdf', name: 'app_bulletin_pdf', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function pdf(BulletinScolaire $bulletin): Response
    {
        $chemin = $bulletin->getFichier() ? $this->documents->chemin($bulletin->getFichier()) : null;
        if (!$chemin) {
            throw $this->createNotFoundException('Aucun PDF joint à ce bulletin.');
        }
        $nom = sprintf('%s %s - %s.pdf', $bulletin->getJoueur()->getNom(), $bulletin->getJoueur()->getPrenom(), $bulletin->getLibelle());

        return (new BinaryFileResponse($chemin))
            ->setContentDisposition(ResponseHeaderBag::DISPOSITION_INLINE, $nom, 'bulletin.pdf')
            ->setPrivate();
    }

    #[IsGranted('ROLE_SCOLAIRE')]
    #[Route('/bulletins/{id}/supprimer', name: 'app_bulletin_supprimer', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function supprimerBulletin(Request $request, BulletinScolaire $bulletin): Response
    {
        $joueur = $bulletin->getJoueur();
        if ($this->isCsrfTokenValid('supprimer-bulletin' . $bulletin->getId(), $request->getPayload()->getString('_token'))) {
            $fichier = $bulletin->getFichier();
            $this->em->remove($bulletin);
            $this->em->flush();
            $this->documents->supprimer($fichier);
            $this->addFlash('succes', 'Bulletin supprimé.');
        }

        return $this->redirectToRoute('app_joueur_voir', ['id' => $joueur->getId(), '_fragment' => 'scolarite']);
    }

    #[Route('/discipline', name: 'app_discipline_index', methods: ['GET'])]
    public function discipline(SanctionRepository $sanctions): Response
    {
        return $this->render('scolarite/discipline.html.twig', [
            'sanctions' => $sanctions->createQueryBuilder('s')
                ->addSelect('j', 'c')->join('s.joueur', 'j')->leftJoin('j.categorie', 'c')
                ->orderBy('s.date', 'DESC')
                ->getQuery()->getResult(),
        ]);
    }

    #[IsGranted('ROLE_SCOLAIRE')]
    #[Route('/discipline/nouvelle', name: 'app_sanction_nouvelle', methods: ['GET', 'POST'])]
    public function nouvelleSanction(Request $request, JoueurRepository $joueurs): Response
    {
        $sanction = (new Sanction())->setDate(new \DateTimeImmutable('today'));
        $joueur = $request->query->getInt('joueur') ? $joueurs->find($request->query->getInt('joueur')) : null;
        $sanction->setJoueur($joueur);

        return $this->formulaireSanction($request, $sanction, 'Nouvel incident de discipline', null !== $joueur);
    }

    #[IsGranted('ROLE_SCOLAIRE')]
    #[Route('/discipline/{id}/modifier', name: 'app_sanction_modifier', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function modifierSanction(Request $request, Sanction $sanction): Response
    {
        return $this->formulaireSanction($request, $sanction, 'Discipline – ' . $sanction->getJoueur()->getNomComplet(), true);
    }

    #[IsGranted('ROLE_SCOLAIRE')]
    #[Route('/discipline/{id}/supprimer', name: 'app_sanction_supprimer', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function supprimerSanction(Request $request, Sanction $sanction): Response
    {
        if ($this->isCsrfTokenValid('supprimer-sanction' . $sanction->getId(), $request->getPayload()->getString('_token'))) {
            $this->em->remove($sanction);
            $this->em->flush();
            $this->addFlash('succes', 'Incident supprimé.');
        }

        return $this->redirectToRoute('app_discipline_index');
    }

    private function formulaireBulletin(Request $request, BulletinScolaire $bulletin, string $titre, bool $joueurFixe): Response
    {
        $form = $this->createForm(BulletinScolaireType::class, $bulletin, ['joueur_fixe' => $joueurFixe]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            if ($pdf = $form->get('document')->getData()) {
                $bulletin->setFichier($this->documents->enregistrer($pdf, 'bulletin', $bulletin->getFichier()));
            }
            $this->em->persist($bulletin);
            $this->em->flush();
            $this->addFlash('succes', 'Bulletin enregistré.');

            return $this->redirectToRoute('app_joueur_voir', ['id' => $bulletin->getJoueur()->getId(), '_fragment' => 'scolarite']);
        }

        $vue = [
            'form' => $form,
            'titre' => $titre,
            'retour' => $bulletin->getJoueur()
                ? $this->generateUrl('app_joueur_voir', ['id' => $bulletin->getJoueur()->getId(), '_fragment' => 'scolarite'])
                : $this->generateUrl('app_scolarite_index'),
        ];
        if ($bulletin->getOrigine() && !$bulletin->getFichier()) {
            $vue['aide'] = 'Dans Access, ce bulletin était un lien vers ' . $bulletin->getOrigine() . ' : joignez ce fichier PDF s\'il est encore disponible.';
        }
        if ($bulletin->getId()) {
            $vue['suppression'] = [
                'action' => $this->generateUrl('app_bulletin_supprimer', ['id' => $bulletin->getId()]),
                'jeton' => 'supprimer-bulletin' . $bulletin->getId(),
                'question' => 'Supprimer ce bulletin' . ($bulletin->getFichier() ? ' et son PDF' : '') . ' ?',
            ];
        }

        return $this->render('commun/formulaire.html.twig', $vue);
    }

    private function formulaireSanction(Request $request, Sanction $sanction, string $titre, bool $joueurFixe): Response
    {
        $form = $this->createForm(SanctionType::class, $sanction, ['joueur_fixe' => $joueurFixe]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->em->persist($sanction);
            $this->em->flush();
            $this->addFlash('succes', 'Incident enregistré.');

            return $this->redirectToRoute('app_joueur_voir', ['id' => $sanction->getJoueur()->getId(), '_fragment' => 'scolarite']);
        }

        $vue = ['form' => $form, 'titre' => $titre, 'retour' => $this->generateUrl('app_discipline_index')];
        if ($sanction->getId()) {
            $vue['suppression'] = [
                'action' => $this->generateUrl('app_sanction_supprimer', ['id' => $sanction->getId()]),
                'jeton' => 'supprimer-sanction' . $sanction->getId(),
                'question' => 'Supprimer cet incident ?',
            ];
        }

        return $this->render('commun/formulaire.html.twig', $vue);
    }
}
