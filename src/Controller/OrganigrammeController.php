<?php

namespace App\Controller;

use App\Entity\PosteOrganigramme;
use App\Form\PosteOrganigrammeType;
use App\Repository\PosteOrganigrammeRepository;
use App\Service\Encadrement;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Organigramme du club : arbre de postes (président, bureau, responsables…) avec la personne de chacun.
 * Consulté par l'encadrement, modifié par l'administration.
 */
#[Route('/organigramme')]
class OrganigrammeController extends AbstractController
{
    /** Organigramme type proposé au départ : poste => postes placés dessous. */
    private const MODELE = [
        'Président' => [
            'Vice-président' => [],
            'Trésorier' => [],
            'Secrétaire' => [],
            'Responsable technique' => [
                'Responsable école de foot' => [],
                'Responsable préformation' => [],
                'Responsable formation' => [],
                'Responsable seniors' => [],
            ],
        ],
    ];

    public function __construct(
        private readonly PosteOrganigrammeRepository $postes,
        private readonly EntityManagerInterface $em,
        private readonly Encadrement $encadrement,
    ) {
    }

    #[Route('', name: 'app_organigramme', methods: ['GET'])]
    #[IsGranted('ROLE_ENCADREMENT')]
    public function index(): Response
    {
        $tous = $this->postes->findBy([], ['ordre' => 'ASC', 'id' => 'ASC']);

        return $this->render('organigramme/index.html.twig', [
            'racines' => array_values(array_filter($tous, fn (PosteOrganigramme $p) => null === $p->getParent())),
            'fiches' => $this->encadrement->trouver(array_filter(array_map(fn (PosteOrganigramme $p) => $p->getPersonne(), $tous))),
            'vide' => !$tous,
        ]);
    }

    #[Route('/modele', name: 'app_organigramme_modele', methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function modele(Request $request): Response
    {
        if (!$this->postes->count([]) && $this->isCsrfTokenValid('organigramme-modele', $request->getPayload()->getString('_token'))) {
            $this->creerModele(self::MODELE, null);
            $this->em->flush();
            $this->addFlash('succes', 'Organigramme type créé : choisissez la personne de chaque poste, ajoutez ou supprimez des postes.');
        }

        return $this->redirectToRoute('app_organigramme');
    }

    #[Route('/nouveau', name: 'app_organigramme_nouveau', methods: ['GET', 'POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function nouveau(Request $request): Response
    {
        $poste = (new PosteOrganigramme())->setParent($this->postes->find($request->query->getInt('parent')));

        return $this->formulaire($request, $poste, 'Nouveau poste');
    }

    #[Route('/{id}/modifier', name: 'app_organigramme_modifier', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function modifier(Request $request, PosteOrganigramme $poste): Response
    {
        return $this->formulaire($request, $poste, 'Modifier « ' . $poste . ' »');
    }

    #[Route('/{id}/supprimer', name: 'app_organigramme_supprimer', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function supprimer(Request $request, PosteOrganigramme $poste): Response
    {
        if ($this->isCsrfTokenValid('supprimer-poste' . $poste->getId(), $request->getPayload()->getString('_token'))) {
            $this->supprimerAvecEnfants($poste);
            $this->em->flush();
            $this->addFlash('succes', 'Poste supprimé.');
        }

        return $this->redirectToRoute('app_organigramme');
    }

    /** Avance ou recule un poste parmi ceux qui ont le même poste au-dessus. */
    #[Route('/{id}/deplacer/{sens}', name: 'app_organigramme_deplacer', requirements: ['id' => '\d+', 'sens' => 'avant|apres'], methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function deplacer(Request $request, PosteOrganigramme $poste, string $sens): Response
    {
        if ($this->isCsrfTokenValid('deplacer-poste' . $poste->getId(), $request->getPayload()->getString('_token'))) {
            $freres = $this->postes->findBy(['parent' => $poste->getParent()], ['ordre' => 'ASC', 'id' => 'ASC']);
            $i = array_search($poste, $freres, true);
            $j = 'avant' === $sens ? $i - 1 : $i + 1;
            if (isset($freres[$j])) {
                [$freres[$i], $freres[$j]] = [$freres[$j], $freres[$i]];
                foreach ($freres as $rang => $f) {
                    $f->setOrdre($rang + 1);
                }
                $this->em->flush();
            }
        }

        return $this->redirectToRoute('app_organigramme', ['_fragment' => 'poste-' . $poste->getId()]);
    }

    private function formulaire(Request $request, PosteOrganigramme $poste, string $titre): Response
    {
        // Un poste ne peut pas être placé sous lui-même ni sous l'un de ses descendants.
        $parents = array_values(array_filter(
            $this->postes->findBy([], ['titre' => 'ASC']),
            fn (PosteOrganigramme $p) => !$poste->getId() || !$poste->contient($p),
        ));
        $form = $this->createForm(PosteOrganigrammeType::class, $poste, ['parents' => $parents, 'personnes' => $this->encadrement->choix()]);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            if (!$poste->getId()) {
                $poste->setOrdre(\count($this->postes->findBy(['parent' => $poste->getParent()])) + 1);
            }
            $this->em->persist($poste);
            $this->em->flush();
            $this->addFlash('succes', 'Poste enregistré.');

            return $this->redirectToRoute('app_organigramme', ['_fragment' => 'poste-' . $poste->getId()]);
        }

        return $this->render('commun/formulaire.html.twig', [
            'form' => $form,
            'titre' => $titre,
            'retour' => $this->generateUrl('app_organigramme'),
        ] + ($poste->getId() ? ['suppression' => [
            'action' => $this->generateUrl('app_organigramme_supprimer', ['id' => $poste->getId()]),
            'jeton' => 'supprimer-poste' . $poste->getId(),
            'question' => 'Supprimer le poste « ' . $poste . ' »' . ($poste->getEnfants()->isEmpty() ? '' : ' et les postes placés dessous') . ' ?',
        ]] : []));
    }

    private function creerModele(array $postes, ?PosteOrganigramme $parent): void
    {
        $ordre = 0;
        foreach ($postes as $titre => $dessous) {
            $poste = (new PosteOrganigramme())->setTitre($titre)->setParent($parent)->setOrdre(++$ordre);
            $this->em->persist($poste);
            $this->creerModele($dessous, $poste);
        }
    }

    private function supprimerAvecEnfants(PosteOrganigramme $poste): void
    {
        foreach ($poste->getEnfants() as $enfant) {
            $this->supprimerAvecEnfants($enfant);
        }
        $this->em->remove($poste);
    }
}
