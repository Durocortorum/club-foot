<?php

namespace App\Controller;

use App\Entity\Categorie;
use App\Entity\Deplacement;
use App\Entity\Rencontre;
use App\Entity\Vehicule;
use App\Form\DeplacementType;
use App\Form\VehiculeType;
use App\Repository\CategorieRepository;
use App\Repository\DeplacementRepository;
use App\Repository\RencontreRepository;
use App\Repository\TournoiRepository;
use App\Repository\VehiculeRepository;
use App\Security\JoueurVoter;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Transport et frais : déplacements (car, minibus du club, voitures personnelles, train), frais kilométriques
 * par conducteur (remboursés ou abandonnés au club, avec récapitulatif pour le reçu fiscal), véhicules du club
 * et leurs échéances. L'encadrement saisit les déplacements de ses équipes ; le trésorier (droit « Finances »)
 * règle les frais ; l'administrateur gère les véhicules.
 */
#[Route('/transport')]
class TransportController extends AbstractController
{
    public function __construct(
        private readonly JoueurVoter $voter,
        private readonly CategorieRepository $categories,
        private readonly EntityManagerInterface $em,
    ) {
    }

    #[Route('', name: 'app_transport', methods: ['GET'])]
    public function index(Request $request, DeplacementRepository $deplacements, VehiculeRepository $vehicules): Response
    {
        $saison = $this->saison($request);
        [$debut, $fin] = Rencontre::bornesSaison($saison);
        $liste = $deplacements->createQueryBuilder('d')
            ->leftJoin('d.categorie', 'c')->addSelect('c')
            ->andWhere('d.date BETWEEN :debut AND :fin')->setParameter('debut', $debut->format('Y-m-d'))->setParameter('fin', $fin->format('Y-m-d'))
            ->orderBy('d.date', 'DESC')->addOrderBy('d.id', 'DESC')
            ->getQuery()->getResult();
        $alertes = [];
        foreach ($vehicules->findBy([], ['nom' => 'ASC']) as $v) {
            foreach ($v->getAlertes() as $a) {
                $alertes[] = ['vehicule' => $v] + $a;
            }
        }

        return $this->render('transport/index.html.twig', [
            'deplacements' => $liste,
            'saison' => $saison,
            'saisons' => self::saisons(),
            'alertes' => $alertes,
            'peut_creer' => $this->peutCreer(),
            'km' => array_sum(array_map(fn (Deplacement $d) => $d->getKm() ?? 0, $liste)),
            'cout' => array_sum(array_map(fn (Deplacement $d) => $d->getMontant(), $liste)),
        ]);
    }

    #[Route('/nouveau', name: 'app_transport_nouveau', methods: ['GET', 'POST'])]
    public function nouveau(Request $request, RencontreRepository $rencontres, TournoiRepository $tournois): Response
    {
        if (!$this->peutCreer()) {
            throw $this->createAccessDeniedException();
        }
        $d = (new Deplacement())->setDate(new \DateTimeImmutable('today'));
        // Depuis un match ou un tournoi : date, équipe et objet déjà remplis.
        if (ctype_digit($id = $request->query->getString('match')) && $m = $rencontres->find((int) $id)) {
            $d->setDate($m->getDate())->setCategorie($m->getCategorie())->setObjet('Match ' . $m->getEquipeAffichee() . ' : ' . $m->getDomicile() . ' – ' . $m->getExterieur());
        } elseif (ctype_digit($id = $request->query->getString('tournoi')) && $t = $tournois->find((int) $id)) {
            $d->setDate($t->getDebut())->setCategorie($t->getCategorie())->setObjet('Tournoi : ' . $t->getNom() . ($t->getLieu() ? ' (' . $t->getLieu() . ')' : ''));
        }

        return $this->formulaire($request, $d, 'Nouveau déplacement');
    }

    #[Route('/{id}/modifier', name: 'app_transport_modifier', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function modifier(Request $request, Deplacement $deplacement): Response
    {
        $this->verifierModifiable($deplacement);

        return $this->formulaire($request, $deplacement, 'Déplacement du ' . $deplacement->getDate()?->format('d/m/Y'));
    }

    #[Route('/{id}/supprimer', name: 'app_transport_supprimer', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function supprimer(Request $request, Deplacement $deplacement): Response
    {
        $this->verifierModifiable($deplacement);
        if ($this->isCsrfTokenValid('supprimer-deplacement' . $deplacement->getId(), $request->getPayload()->getString('_token'))) {
            $this->em->remove($deplacement);
            $this->em->flush();
            $this->addFlash('succes', 'Déplacement supprimé.');
        }

        return $this->redirectToRoute('app_transport');
    }

    // ———————————————————————— Frais kilométriques ————————————————————————

    /** Frais des voitures personnelles d'une année civile (celle du reçu fiscal), par conducteur. */
    #[Route('/frais', name: 'app_transport_frais', methods: ['GET'])]
    public function frais(Request $request, DeplacementRepository $deplacements): Response
    {
        $annee = $this->annee($request);
        $parConducteur = [];
        foreach ($this->fraisDeLAnnee($deplacements, $annee) as $d) {
            $c = $d->getConducteur() ?? '?';
            $parConducteur[$c] ??= ['ancre' => self::ancre($c), 'deplacements' => [], 'km' => 0, Deplacement::A_REMBOURSER => 0.0, Deplacement::REMBOURSE => 0.0, Deplacement::ABANDONNE => 0.0];
            $parConducteur[$c]['deplacements'][] = $d;
            $parConducteur[$c]['km'] += $d->getKm() ?? 0;
            $parConducteur[$c][$d->getStatut() ?? Deplacement::A_REMBOURSER] += $d->getMontant();
        }
        ksort($parConducteur, \SORT_NATURAL | \SORT_FLAG_CASE);

        return $this->render('transport/frais.html.twig', [
            'annee' => $annee,
            'annees' => range((int) date('Y'), (int) date('Y') - 4),
            'conducteurs' => $parConducteur,
            'totaux' => [
                Deplacement::A_REMBOURSER => array_sum(array_column($parConducteur, Deplacement::A_REMBOURSER)),
                Deplacement::REMBOURSE => array_sum(array_column($parConducteur, Deplacement::REMBOURSE)),
                Deplacement::ABANDONNE => array_sum(array_column($parConducteur, Deplacement::ABANDONNE)),
            ],
            'peut_regler' => $this->isGranted('ROLE_FINANCES'),
        ]);
    }

    /** Le trésorier règle les frais : remboursés, abandonnés au club (don), ou de nouveau à rembourser. */
    #[Route('/{id}/statut', name: 'app_transport_statut', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function statut(Request $request, Deplacement $deplacement): Response
    {
        $this->denyAccessUnlessGranted('ROLE_FINANCES');
        $p = $request->getPayload();
        $statut = $p->getString('statut');
        if ($deplacement->isVoiture() && isset(Deplacement::STATUTS[$statut]) && $this->isCsrfTokenValid('statut-deplacement' . $deplacement->getId(), $p->getString('_token'))) {
            $deplacement->setStatut($statut)->setRegleLe(Deplacement::A_REMBOURSER === $statut ? null : new \DateTimeImmutable('today'));
            $this->em->flush();
            $this->addFlash('succes', \sprintf('%s, %s : %s.', $deplacement->getConducteur(), $deplacement->getDate()->format('d/m'), mb_strtolower(Deplacement::STATUTS[$statut])));
        }

        return $this->redirectToRoute('app_transport_frais', ['annee' => $deplacement->getDate()->format('Y'), '_fragment' => self::ancre((string) $deplacement->getConducteur())]);
    }

    /** Récapitulatif imprimable des frais abandonnés au club par un bénévole sur une année (pour son reçu fiscal). */
    #[Route('/frais/recapitulatif', name: 'app_transport_recapitulatif', methods: ['GET'])]
    public function recapitulatif(Request $request, DeplacementRepository $deplacements): Response
    {
        $annee = $this->annee($request);
        $conducteur = trim($request->query->getString('conducteur'));
        $liste = array_values(array_filter(
            $this->fraisDeLAnnee($deplacements, $annee),
            fn (Deplacement $d) => $d->getConducteur() === $conducteur && Deplacement::ABANDONNE === $d->getStatut(),
        ));

        return $this->render('transport/recapitulatif.html.twig', [
            'annee' => $annee,
            'conducteur' => $conducteur,
            'deplacements' => $liste,
            'total' => array_sum(array_map(fn (Deplacement $d) => $d->getMontant(), $liste)),
            'km' => array_sum(array_map(fn (Deplacement $d) => $d->getKm() ?? 0, $liste)),
        ]);
    }

    // ———————————————————————— Véhicules ————————————————————————

    #[Route('/vehicules', name: 'app_transport_vehicules', methods: ['GET'])]
    public function vehicules(VehiculeRepository $vehicules): Response
    {
        return $this->render('transport/vehicules.html.twig', ['vehicules' => $vehicules->findBy([], ['nom' => 'ASC'])]);
    }

    #[Route('/vehicules/nouveau', name: 'app_transport_vehicule_nouveau', methods: ['GET', 'POST'])]
    public function nouveauVehicule(Request $request): Response
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');

        return $this->formulaireVehicule($request, new Vehicule(), 'Nouveau véhicule');
    }

    #[Route('/vehicules/{id}/modifier', name: 'app_transport_vehicule_modifier', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function modifierVehicule(Request $request, Vehicule $vehicule): Response
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');

        return $this->formulaireVehicule($request, $vehicule, (string) $vehicule);
    }

    #[Route('/vehicules/{id}/supprimer', name: 'app_transport_vehicule_supprimer', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function supprimerVehicule(Request $request, Vehicule $vehicule): Response
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');
        if ($this->isCsrfTokenValid('supprimer-vehicule' . $vehicule->getId(), $request->getPayload()->getString('_token'))) {
            $this->em->remove($vehicule);
            $this->em->flush();
            $this->addFlash('succes', 'Véhicule supprimé.');
        }

        return $this->redirectToRoute('app_transport_vehicules');
    }

    // ———————————————————————— Outils ————————————————————————

    private function formulaire(Request $request, Deplacement $deplacement, string $titre): Response
    {
        $proposees = $this->isGranted('ROLE_ADMIN') ? $this->categories->findBy([], ['ordre' => 'ASC']) : $this->modifiables();
        if ($deplacement->getCategorie() && !\in_array($deplacement->getCategorie(), $proposees, true)) {
            $proposees[] = $deplacement->getCategorie();
        }
        $form = $this->createForm(DeplacementType::class, $deplacement, ['categories' => $proposees]);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $this->em->persist($deplacement);
            $this->em->flush();
            $this->addFlash('succes', 'Déplacement enregistré' . ($deplacement->isVoiture() ? \sprintf(' : %s € de frais pour %s.', number_format($deplacement->getMontant(), 2, ',', ' '), $deplacement->getConducteur()) : '.'));

            return $this->redirectToRoute('app_transport', ['saison' => Rencontre::saisonDe($deplacement->getDate())]);
        }

        return $this->render('commun/formulaire.html.twig', [
            'form' => $form,
            'titre' => $titre,
            'retour' => $this->generateUrl('app_transport'),
        ] + ($deplacement->getId() ? ['suppression' => [
            'action' => $this->generateUrl('app_transport_supprimer', ['id' => $deplacement->getId()]),
            'jeton' => 'supprimer-deplacement' . $deplacement->getId(),
            'question' => 'Supprimer ce déplacement ?',
        ]] : []));
    }

    private function formulaireVehicule(Request $request, Vehicule $vehicule, string $titre): Response
    {
        $form = $this->createForm(VehiculeType::class, $vehicule);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $this->em->persist($vehicule);
            $this->em->flush();
            $this->addFlash('succes', 'Véhicule enregistré.');

            return $this->redirectToRoute('app_transport_vehicules');
        }

        return $this->render('commun/formulaire.html.twig', [
            'form' => $form,
            'titre' => $titre,
            'retour' => $this->generateUrl('app_transport_vehicules'),
        ] + ($vehicule->getId() ? ['suppression' => [
            'action' => $this->generateUrl('app_transport_vehicule_supprimer', ['id' => $vehicule->getId()]),
            'jeton' => 'supprimer-vehicule' . $vehicule->getId(),
            'question' => 'Supprimer « ' . $vehicule . ' » ? Les déplacements faits avec restent.',
        ]] : []));
    }

    /** @return list<Deplacement> voitures personnelles de l'année civile */
    private function fraisDeLAnnee(DeplacementRepository $deplacements, int $annee): array
    {
        return $deplacements->createQueryBuilder('d')
            ->andWhere('d.moyen = :voiture')->setParameter('voiture', Deplacement::VOITURE)
            ->andWhere('d.date BETWEEN :debut AND :fin')->setParameter('debut', "$annee-01-01")->setParameter('fin', "$annee-12-31")
            ->orderBy('d.date')->addOrderBy('d.id')
            ->getQuery()->getResult();
    }

    /** @return list<Categorie> */
    private function modifiables(): array
    {
        return $this->categories->findBy(['secteur' => $this->voter->secteursModifiables() ?: ['-']], ['ordre' => 'ASC']);
    }

    private function peutCreer(): bool
    {
        return $this->isGranted('ROLE_ADMIN') || $this->isGranted('ROLE_FINANCES') || [] !== $this->modifiables();
    }

    /** Administrateur et trésorier : tous ; éducateur : ceux de ses équipes (ou sans équipe). */
    private function verifierModifiable(Deplacement $d): void
    {
        $ok = $this->isGranted('ROLE_ADMIN') || $this->isGranted('ROLE_FINANCES')
            || ([] !== $this->modifiables() && (null === $d->getCategorie() || \in_array($d->getCategorie(), $this->modifiables(), true)));
        if (!$ok) {
            throw $this->createAccessDeniedException();
        }
    }

    private function saison(Request $request): string
    {
        return \in_array($s = $request->query->getString('saison'), self::saisons(), true) ? $s : Rencontre::saisonDe(new \DateTimeImmutable('today'));
    }

    /** @return list<string> */
    private static function saisons(): array
    {
        $annee = (int) substr(Rencontre::saisonDe(new \DateTimeImmutable('today')), 0, 4);

        return array_map(fn (int $a) => $a . '/' . ($a + 1), range($annee + 1, $annee - 4));
    }

    private static function ancre(string $conducteur): string
    {
        return 'conducteur-' . substr(md5($conducteur), 0, 8);
    }

    private function annee(Request $request): int
    {
        $a = $request->query->getString('annee');

        return ctype_digit($a) && (int) $a >= 2000 && (int) $a <= (int) date('Y') + 1 ? (int) $a : (int) date('Y');
    }
}
