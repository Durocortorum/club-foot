<?php

namespace App\Controller;

use App\Entity\ContactUtile;
use App\Entity\PosteOrganigramme;
use App\Form\ContactUtileType;
use App\Repository\ContactUtileRepository;
use App\Repository\PosteOrganigrammeRepository;
use App\Service\Encadrement;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Numéros utiles : coordonnées du club, bureau (repris de l'organigramme avec les téléphones des fiches)
 * et numéros saisis par le club (stade, comptable, district, urgences…).
 * Consultés par l'encadrement, modifiés par l'administration.
 */
#[Route('/numeros-utiles')]
class ContactUtileController extends AbstractController
{
    public function __construct(
        private readonly ContactUtileRepository $contacts,
        private readonly EntityManagerInterface $em,
    ) {
    }

    #[Route('', name: 'app_numero_utile_index', methods: ['GET'])]
    public function index(PosteOrganigrammeRepository $postes, Encadrement $encadrement): Response
    {
        // Bureau : postes de l'organigramme dans leur ordre (chaque poste suivi de ceux qui en dépendent).
        $tous = $postes->findBy([], ['ordre' => 'ASC', 'id' => 'ASC']);
        $ordonnes = [];
        $parcourir = function (?PosteOrganigramme $parent) use (&$parcourir, &$ordonnes, $tous): void {
            foreach ($tous as $p) {
                if ($p->getParent() === $parent) {
                    $ordonnes[] = $p;
                    $parcourir($p);
                }
            }
        };
        $parcourir(null);
        $fiches = $encadrement->trouver(array_values(array_filter(array_map(fn (PosteOrganigramme $p) => $p->getPersonne(), $tous))));
        $bureau = [];
        foreach ($ordonnes as $p) {
            $fiche = $p->getPersonne() ? $fiches[$p->getPersonne()] ?? null : null;
            if ($fiche || $p->getNomLibre()) {
                $bureau[] = ['poste' => $p, 'fiche' => $fiche];
            }
        }

        $parRubrique = array_fill_keys(array_keys(ContactUtile::RUBRIQUES), []);
        foreach ($this->contacts->findBy([], ['libelle' => 'ASC']) as $c) {
            $parRubrique[$c->getRubrique()][] = $c;
        }

        return $this->render('contact_utile/index.html.twig', [
            'bureau' => $bureau,
            'rubriques' => array_filter($parRubrique),
            'urgences_nationales' => $this->urgencesManquantes(),
        ]);
    }

    #[Route('/nouveau', name: 'app_numero_utile_nouveau', methods: ['GET', 'POST'])]
    #[Route('/{id}/modifier', name: 'app_numero_utile_modifier', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function formulaire(Request $request, ?ContactUtile $contact = null): Response
    {
        if (!$contact) {
            $contact = new ContactUtile();
            if (isset(ContactUtile::RUBRIQUES[$r = $request->query->getString('rubrique')])) {
                $contact->setRubrique($r);
            }
        }
        $form = $this->createForm(ContactUtileType::class, $contact);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->em->persist($contact);
            $this->em->flush();
            $this->addFlash('succes', 'Numéro enregistré.');

            return $this->redirectToRoute('app_numero_utile_index', ['_fragment' => $contact->getRubrique()]);
        }

        return $this->render('commun/formulaire.html.twig', [
            'form' => $form,
            'titre' => $contact->getId() ? 'Modifier le numéro' : 'Nouveau numéro utile',
            'retour' => $this->generateUrl('app_numero_utile_index'),
            'aide' => 'Le président, le trésorier et les autres membres du bureau viennent de l\'organigramme : inutile de les saisir ici.',
        ] + ($contact->getId() ? ['suppression' => [
            'action' => $this->generateUrl('app_numero_utile_supprimer', ['id' => $contact->getId()]),
            'jeton' => 'supprimer-contact-utile' . $contact->getId(),
            'question' => 'Supprimer ce numéro ?',
        ]] : []));
    }

    #[Route('/{id}/supprimer', name: 'app_numero_utile_supprimer', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function supprimer(Request $request, ContactUtile $contact): Response
    {
        if ($this->isCsrfTokenValid('supprimer-contact-utile' . $contact->getId(), $request->getPayload()->getString('_token'))) {
            $this->em->remove($contact);
            $this->em->flush();
            $this->addFlash('succes', 'Numéro supprimé.');
        }

        return $this->redirectToRoute('app_numero_utile_index');
    }

    /**
     * Numéros d'urgence nationaux, toujours affichés en tête de page
     * (sauf ceux que le club a déjà saisis lui-même dans la rubrique Urgences).
     *
     * @return list<array{0: string, 1: string}>
     */
    private function urgencesManquantes(): array
    {
        $presents = array_map(fn (ContactUtile $c) => $c->getTelephone(), $this->contacts->findBy(['rubrique' => 'urgences']));

        return array_values(array_filter(ContactUtile::URGENCES, fn (array $u) => !\in_array($u[1], $presents, true)));
    }
}
