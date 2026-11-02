<?php

namespace App\Controller;

use App\Entity\Blessure;
use App\Entity\ContactMedical;
use App\Form\BlessureType;
use App\Form\ContactMedicalType;
use App\Repository\BlessureRepository;
use App\Repository\CategorieRepository;
use App\Repository\ContactMedicalRepository;
use App\Repository\JoueurRepository;
use App\Service\Anatomie;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Suivi des blessures et carnet d'adresses médical. Réservé à l'infirmerie.
 */
#[Route('/infirmerie')]
#[IsGranted('ROLE_INFIRMERIE')]
class InfirmerieController extends AbstractController
{
    public function __construct(private readonly Anatomie $anatomie)
    {
    }

    #[Route('', name: 'app_infirmerie_index', methods: ['GET'])]
    public function index(Request $request, BlessureRepository $blessures, CategorieRepository $categories): Response
    {
        $filtres = [
            'statut' => 'toutes' === $request->query->getString('statut') ? 'toutes' : 'en_cours',
            'q' => trim($request->query->getString('q')) ?: null,
            'categorie' => $request->query->getInt('categorie') ?: null,
        ];
        $page = max(1, $request->query->getInt('page', 1));
        $resultats = $blessures->rechercher($filtres['statut'], $filtres['q'], $filtres['categorie'], $page);

        return $this->render('infirmerie/index.html.twig', [
            'blessures' => $resultats,
            'total' => \count($resultats),
            'pages' => max(1, (int) ceil(\count($resultats) / BlessureRepository::PAR_PAGE)),
            'page' => $page,
            'filtres' => $filtres,
            'categories' => $categories->findBy([], ['ordre' => 'ASC']),
            'par_type' => $blessures->compterParType(),
        ]);
    }

    #[Route('/blessures/nouvelle', name: 'app_blessure_nouvelle', methods: ['GET', 'POST'])]
    public function nouvelleBlessure(Request $request, EntityManagerInterface $em, JoueurRepository $joueurs): Response
    {
        $blessure = (new Blessure())->setDate(new \DateTimeImmutable('today'));
        $joueur = $request->query->getInt('joueur') ? $joueurs->find($request->query->getInt('joueur')) : null;
        if ($joueur) {
            $blessure->setJoueur($joueur)->setEquipe($joueur->getCategorie()?->getNom());
        }

        return $this->formulaireBlessure($request, $em, $blessure, 'Nouvelle blessure', null !== $joueur);
    }

    #[Route('/blessures/{id}/modifier', name: 'app_blessure_modifier', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function modifierBlessure(Request $request, EntityManagerInterface $em, Blessure $blessure): Response
    {
        return $this->formulaireBlessure($request, $em, $blessure, 'Blessure de ' . $blessure->getJoueur()->getNomComplet(), true);
    }

    #[Route('/blessures/{id}/supprimer', name: 'app_blessure_supprimer', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function supprimerBlessure(Request $request, EntityManagerInterface $em, Blessure $blessure): Response
    {
        $joueur = $blessure->getJoueur();
        if ($this->isCsrfTokenValid('supprimer-blessure' . $blessure->getId(), $request->getPayload()->getString('_token'))) {
            $em->remove($blessure);
            $em->flush();
            $this->addFlash('succes', 'Blessure supprimée.');
        }

        return $this->redirectToRoute('app_joueur_voir', ['id' => $joueur->getId(), '_fragment' => 'infirmerie']);
    }

    #[Route('/contacts', name: 'app_contact_index', methods: ['GET'])]
    public function contacts(ContactMedicalRepository $contacts): Response
    {
        return $this->render('infirmerie/contacts.html.twig', [
            'contacts' => $contacts->findBy([], ['profession' => 'ASC', 'nom' => 'ASC']),
        ]);
    }

    #[Route('/contacts/nouveau', name: 'app_contact_nouveau', methods: ['GET', 'POST'])]
    #[Route('/contacts/{id}/modifier', name: 'app_contact_modifier', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function formulaireContact(Request $request, EntityManagerInterface $em, ?ContactMedical $contact = null): Response
    {
        $contact ??= new ContactMedical();
        $form = $this->createForm(ContactMedicalType::class, $contact);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $em->persist($contact);
            $em->flush();
            $this->addFlash('succes', 'Contact enregistré.');

            return $this->redirectToRoute('app_contact_index');
        }

        return $this->render('commun/formulaire.html.twig', [
            'form' => $form,
            'titre' => $contact->getId() ? 'Modifier le contact' : 'Nouveau contact médical',
            'retour' => $this->generateUrl('app_contact_index'),
        ]);
    }

    #[Route('/contacts/{id}/supprimer', name: 'app_contact_supprimer', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function supprimerContact(Request $request, EntityManagerInterface $em, ContactMedical $contact): Response
    {
        if ($this->isCsrfTokenValid('supprimer-contact' . $contact->getId(), $request->getPayload()->getString('_token'))) {
            $em->remove($contact);
            $em->flush();
            $this->addFlash('succes', 'Contact supprimé.');
        }

        return $this->redirectToRoute('app_contact_index');
    }

    private function formulaireBlessure(Request $request, EntityManagerInterface $em, Blessure $blessure, string $titre, bool $joueurFixe): Response
    {
        $form = $this->createForm(BlessureType::class, $blessure, ['joueur_fixe' => $joueurFixe]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $em->persist($blessure);
            $em->flush();
            $this->addFlash('succes', 'Blessure enregistrée.');

            return $this->redirectToRoute('app_joueur_voir', ['id' => $blessure->getJoueur()->getId(), '_fragment' => 'infirmerie']);
        }

        $vue = [
            'form' => $form,
            'titre' => $titre,
            'planches' => $this->anatomie->planches(),
            'retour' => $blessure->getJoueur()
                ? $this->generateUrl('app_joueur_voir', ['id' => $blessure->getJoueur()->getId()])
                : $this->generateUrl('app_infirmerie_index'),
        ];
        if ($blessure->getId()) {
            $vue['suppression'] = [
                'action' => $this->generateUrl('app_blessure_supprimer', ['id' => $blessure->getId()]),
                'jeton' => 'supprimer-blessure' . $blessure->getId(),
                'question' => 'Supprimer cette blessure ?',
            ];
        }

        return $this->render('infirmerie/blessure.html.twig', $vue);
    }
}
