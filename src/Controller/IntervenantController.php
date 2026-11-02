<?php

namespace App\Controller;

use App\Entity\Dirigeant;
use App\Entity\Educateur;
use App\Entity\Intervenant;
use App\Entity\Joueur;
use App\Form\IntervenantType;
use App\Service\Photos;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Fiches éducateurs et dirigeants. Consultables par tous, modifiables par l'administration.
 */
#[Route('/encadrement/{type}', requirements: ['type' => 'educateurs|dirigeants'])]
class IntervenantController extends AbstractController
{
    private const TYPES = [
        'educateurs' => ['classe' => Educateur::class, 'singulier' => 'éducateur', 'pluriel' => 'Éducateurs', 'photo' => 'educateur'],
        'dirigeants' => ['classe' => Dirigeant::class, 'singulier' => 'dirigeant', 'pluriel' => 'Dirigeants', 'photo' => 'dirigeant'],
    ];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly Photos $photos,
    ) {
    }

    #[Route('', name: 'app_intervenant_index', methods: ['GET'])]
    public function index(Request $request, string $type): Response
    {
        $texte = trim($request->query->getString('q')) ?: null;
        $statut = \in_array($s = $request->query->getString('statut', 'actifs'), ['actifs', 'anciens', 'tous'], true) ? $s : 'actifs';

        $qb = $this->em->getRepository(self::TYPES[$type]['classe'])->createQueryBuilder('i')
            ->orderBy('i.nom')->addOrderBy('i.prenom');
        if ($texte) {
            $qb->andWhere('i.nom LIKE :t OR i.prenom LIKE :t OR i.fonction LIKE :t')->setParameter('t', '%' . $texte . '%');
        }
        if ('tous' !== $statut) {
            $qb->andWhere('i.ancien = :ancien')->setParameter('ancien', 'anciens' === $statut);
        }

        return $this->render('intervenant/index.html.twig', [
            'type' => $type,
            'libelles' => self::TYPES[$type],
            'intervenants' => $qb->getQuery()->getResult(),
            'filtres' => ['q' => $texte, 'statut' => $statut],
        ]);
    }

    #[Route('/nouveau', name: 'app_intervenant_nouveau', methods: ['GET', 'POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function nouveau(Request $request, string $type): Response
    {
        $classe = self::TYPES[$type]['classe'];

        return $this->formulaire($request, $type, new $classe(), 'Nouvel ' . self::TYPES[$type]['singulier']);
    }

    #[Route('/{id}', name: 'app_intervenant_voir', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function voir(string $type, int $id): Response
    {
        $intervenant = $this->trouver($type, $id);

        return $this->render('intervenant/voir.html.twig', [
            'type' => $type,
            'libelles' => self::TYPES[$type],
            'intervenant' => $intervenant,
            // Joueurs suivis : seul l'éducateur est rattaché aux fiches joueurs dans Access.
            'joueurs' => $intervenant instanceof Educateur
                ? $this->em->getRepository(Joueur::class)->findBy(['educateur' => $intervenant, 'ancien' => false], ['nom' => 'ASC'])
                : [],
        ]);
    }

    #[Route('/{id}/modifier', name: 'app_intervenant_modifier', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function modifier(Request $request, string $type, int $id): Response
    {
        $intervenant = $this->trouver($type, $id);

        return $this->formulaire($request, $type, $intervenant, 'Modifier ' . $intervenant->getNomComplet());
    }

    #[Route('/{id}/supprimer', name: 'app_intervenant_supprimer', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function supprimer(Request $request, string $type, int $id): Response
    {
        $intervenant = $this->trouver($type, $id);
        if ($this->isCsrfTokenValid('supprimer' . $type . $id, $request->getPayload()->getString('_token'))) {
            if ($intervenant instanceof Educateur) {
                $this->em->createQuery('UPDATE ' . Joueur::class . ' j SET j.educateur = NULL WHERE j.educateur = :e')
                    ->setParameter('e', $intervenant)
                    ->execute();
            }
            $photo = $intervenant->getPhoto();
            $this->em->remove($intervenant);
            $this->em->flush();
            $this->photos->supprimer($photo);
            $this->addFlash('succes', ucfirst(self::TYPES[$type]['singulier']) . ' supprimé.');
        }

        return $this->redirectToRoute('app_intervenant_index', ['type' => $type]);
    }

    private function formulaire(Request $request, string $type, Intervenant $intervenant, string $titre): Response
    {
        $form = $this->createForm(IntervenantType::class, $intervenant, ['data_class' => self::TYPES[$type]['classe']]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            if ($fichier = $form->get('photo')->getData()) {
                $intervenant->setPhoto($this->photos->enregistrer($fichier, self::TYPES[$type]['photo'], $intervenant->getPhoto()));
            }
            $this->em->persist($intervenant);
            $this->em->flush();
            $this->addFlash('succes', 'Fiche enregistrée.');

            return $this->redirectToRoute('app_intervenant_voir', ['type' => $type, 'id' => $intervenant->getId()]);
        }

        return $this->render('intervenant/formulaire.html.twig', [
            'type' => $type,
            'intervenant' => $intervenant,
            'form' => $form,
            'titre' => $titre,
        ]);
    }

    private function trouver(string $type, int $id): Intervenant
    {
        return $this->em->find(self::TYPES[$type]['classe'], $id) ?? throw $this->createNotFoundException();
    }
}
