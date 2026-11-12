<?php

namespace App\Controller;

use App\Entity\Categorie;
use App\Entity\Contrat;
use App\Entity\Cotisation;
use App\Entity\Joueur;
use App\Entity\Operation;
use App\Entity\Paiement;
use App\Entity\Rencontre;
use App\Entity\TarifCotisation;
use App\Form\ContratType;
use App\Form\OperationType;
use App\Repository\CategorieRepository;
use App\Repository\ContratRepository;
use App\Repository\CotisationRepository;
use App\Repository\DeplacementRepository;
use App\Repository\JoueurRepository;
use App\Repository\OperationRepository;
use App\Repository\TarifCotisationRepository;
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
 * Finances du club, saison par saison : cotisations (tarif par catégorie, paiements de chaque joueur),
 * recettes et dépenses (entrées des matchs, matériel…), contrats. Réservé au droit « Finances ».
 */
#[Route('/finances')]
#[IsGranted('ROLE_FINANCES_LECTURE')]
class FinancesController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly Documents $documents,
    ) {
    }

    /** Synthèse de la saison : cotisations, recettes, dépenses, solde, contrats à échéance. */
    #[Route('', name: 'app_finances', methods: ['GET'])]
    public function index(Request $request, OperationRepository $operations, ContratRepository $contrats, JoueurRepository $joueurs, CotisationRepository $cotisations, TarifCotisationRepository $tarifs, DeplacementRepository $deplacements): Response
    {
        $saison = $this->saison($request);
        $lignes = $this->lignesCotisations($saison, null, $joueurs, $cotisations, $tarifs);
        $liste = $this->operationsDeLaSaison($operations, $saison);
        $parRubrique = ['recette' => [], 'depense' => []];
        foreach ($liste as $o) {
            $parRubrique[$o->getSens()][$o->getRubrique()] = ($parRubrique[$o->getSens()][$o->getRubrique()] ?? 0) + $o->getMontant();
        }
        // Frais kilométriques remboursés aux conducteurs (page Transport) : des dépenses du club.
        [$debut, $fin] = Rencontre::bornesSaison($saison);
        $km = array_sum(array_map(fn ($d) => $d->getMontant(), $deplacements->createQueryBuilder('d')
            ->andWhere('d.statut = :rembourse')->setParameter('rembourse', \App\Entity\Deplacement::REMBOURSE)
            ->andWhere('d.date BETWEEN :debut AND :fin')->setParameter('debut', $debut->format('Y-m-d'))->setParameter('fin', $fin->format('Y-m-d'))
            ->getQuery()->getResult()));
        if ($km > 0) {
            $parRubrique['depense']['Frais kilométriques (remboursés)'] = $km;
        }
        // Amendes de discipline payées pendant la saison (page Discipline) : des recettes du club.
        $amendes = (float) $this->em->createQueryBuilder()->select('SUM(s.amende)')->from(\App\Entity\Sanction::class, 's')
            ->andWhere('s.amendePayeeLe BETWEEN :debut AND :fin')->setParameter('debut', $debut->format('Y-m-d'))->setParameter('fin', $fin->format('Y-m-d'))
            ->getQuery()->getSingleScalarResult();
        if ($amendes > 0) {
            $parRubrique['recette']['Amendes de discipline (encaissées)'] = $amendes;
        }
        arsort($parRubrique['recette']);
        arsort($parRubrique['depense']);
        $encaisse = array_sum(array_column($lignes, 'paye'));
        $recettes = array_sum($parRubrique['recette']);
        $depenses = array_sum($parRubrique['depense']);

        return $this->render('finances/index.html.twig', [
            'saison' => $saison,
            'saisons' => self::saisons(),
            'cotisations' => [
                'attendu' => array_sum(array_column($lignes, 'du')),
                'encaisse' => $encaisse,
                'reste' => array_sum(array_column($lignes, 'reste')),
                'a_jour' => \count(array_filter($lignes, fn (array $l) => 'paye' === $l['statut'])),
                'joueurs' => \count($lignes),
            ],
            'recettes' => $recettes,
            'depenses' => $depenses,
            'solde' => $encaisse + $recettes - $depenses,
            'par_rubrique' => $parRubrique,
            'contrats' => array_values(array_filter($contrats->findBy([], ['fin' => 'ASC']), fn (Contrat $c) => 'bientot' === $c->getEtat())),
        ]);
    }

    // ———————————————————————— Cotisations ————————————————————————

    #[Route('/cotisations', name: 'app_finances_cotisations', methods: ['GET'])]
    public function cotisations(Request $request, CategorieRepository $categories, JoueurRepository $joueurs, CotisationRepository $cotisations, TarifCotisationRepository $tarifs): Response
    {
        $saison = $this->saison($request);
        $categorie = $request->query->getInt('categorie') ? $categories->find($request->query->getInt('categorie')) : null;
        $statut = \in_array($s = $request->query->getString('statut'), ['a_payer', 'partiel', 'paye'], true) ? $s : null;
        $lignes = $this->lignesCotisations($saison, $categorie, $joueurs, $cotisations, $tarifs);
        $toutes = $lignes;
        if ($statut) {
            $lignes = array_values(array_filter($lignes, fn (array $l) => $l['statut'] === $statut || ('a_payer' === $statut && 'partiel' === $l['statut'])));
        }
        $tarifsSaison = [];
        foreach ($tarifs->findBy(['saison' => $saison]) as $t) {
            $tarifsSaison[$t->getCategorie()->getId()] = $t->getMontant();
        }

        return $this->render('finances/cotisations.html.twig', [
            'saison' => $saison,
            'saisons' => self::saisons(),
            'categories' => $categories->findBy([], ['ordre' => 'ASC']),
            'categorie' => $categorie,
            'statut' => $statut,
            'lignes' => $lignes,
            'totaux' => ['du' => array_sum(array_column($toutes, 'du')), 'paye' => array_sum(array_column($toutes, 'paye')), 'reste' => array_sum(array_column($toutes, 'reste'))],
            'tarifs' => $tarifsSaison,
            'modes' => Cotisation::MODES,
        ]);
    }

    #[IsGranted('ROLE_FINANCES')]
    #[Route('/cotisations/tarifs', name: 'app_finances_tarifs', methods: ['POST'])]
    public function tarifs(Request $request, CategorieRepository $categories, TarifCotisationRepository $tarifs): Response
    {
        $p = $request->getPayload();
        $saison = self::saisonValide($p->getString('saison'));
        if ($this->isCsrfTokenValid('tarifs-cotisation', $p->getString('_token'))) {
            foreach ($p->all('tarifs') as $id => $valeur) {
                $categorie = $categories->find((int) $id);
                $montant = self::montant((string) $valeur);
                if (!$categorie) {
                    continue;
                }
                $tarif = $tarifs->findOneBy(['categorie' => $categorie, 'saison' => $saison]);
                if (null === $montant) {
                    if ($tarif) {
                        $this->em->remove($tarif);
                    }
                    continue;
                }
                $tarif ??= new TarifCotisation($categorie, $saison);
                $this->em->persist($tarif->setMontant($montant));
            }
            $this->em->flush();
            $this->addFlash('succes', 'Tarifs ' . $saison . ' enregistrés.');
        }

        return $this->redirectToRoute('app_finances_cotisations', ['saison' => $saison]);
    }

    /** Paiement d'une cotisation (une cotisation peut se payer en plusieurs fois). */
    #[IsGranted('ROLE_FINANCES')]
    #[Route('/cotisations/{id}/paiement', name: 'app_finances_paiement', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function paiement(Request $request, Joueur $joueur, CotisationRepository $cotisations): Response
    {
        $p = $request->getPayload();
        $saison = self::saisonValide($p->getString('saison'));
        if ($this->isCsrfTokenValid('paiement' . $joueur->getId(), $p->getString('_token'))) {
            $montant = self::montant($p->getString('montant'));
            $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $p->getString('date')) ?: new \DateTimeImmutable('today');
            $mode = \in_array($m = $p->getString('mode'), Cotisation::MODES, true) ? $m : 'Autre';
            if (!$montant) {
                $this->addFlash('erreur', 'Indiquez le montant payé.');
            } else {
                $cotisation = $cotisations->findOneBy(['joueur' => $joueur, 'saison' => $saison]) ?? new Cotisation($joueur, $saison);
                $cotisation->ajouterPaiement(new Paiement($cotisation, $date, $montant, $mode, trim($p->getString('note'))));
                $this->em->persist($cotisation);
                $this->em->flush();
                $this->addFlash('succes', \sprintf('Paiement de %s € enregistré pour %s.', self::euros($montant), $joueur->getNomComplet()));
            }
        }

        return $this->retourCotisations($request, $saison, $joueur);
    }

    /** Montant particulier (réduction, fratrie, exonération) ; vide : le tarif de la catégorie. */
    #[IsGranted('ROLE_FINANCES')]
    #[Route('/cotisations/{id}/montant', name: 'app_finances_cotisation_montant', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function montantParticulier(Request $request, Joueur $joueur, CotisationRepository $cotisations): Response
    {
        $p = $request->getPayload();
        $saison = self::saisonValide($p->getString('saison'));
        if ($this->isCsrfTokenValid('montant-cotisation' . $joueur->getId(), $p->getString('_token'))) {
            $cotisation = $cotisations->findOneBy(['joueur' => $joueur, 'saison' => $saison]) ?? new Cotisation($joueur, $saison);
            $cotisation->setMontant(self::montant($p->getString('montant')))->setRemarque(trim($p->getString('remarque')));
            $this->em->persist($cotisation);
            $this->em->flush();
            $this->addFlash('succes', 'Cotisation de ' . $joueur->getNomComplet() . ' mise à jour.');
        }

        return $this->retourCotisations($request, $saison, $joueur);
    }

    #[IsGranted('ROLE_FINANCES')]
    #[Route('/paiements/{id}/supprimer', name: 'app_finances_paiement_supprimer', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function supprimerPaiement(Request $request, Paiement $paiement): Response
    {
        $cotisation = $paiement->getCotisation();
        if ($this->isCsrfTokenValid('supprimer-paiement' . $paiement->getId(), $request->getPayload()->getString('_token'))) {
            $cotisation->getPaiements()->removeElement($paiement);
            $this->em->flush();
            $this->addFlash('succes', 'Paiement supprimé.');
        }

        return $this->retourCotisations($request, $cotisation->getSaison(), $cotisation->getJoueur());
    }

    // ———————————————————————— Recettes et dépenses ————————————————————————

    #[Route('/operations', name: 'app_finances_operations', methods: ['GET'])]
    public function operations(Request $request, OperationRepository $operations): Response
    {
        $saison = $this->saison($request);
        $sens = \in_array($s = $request->query->getString('sens'), [Operation::RECETTE, Operation::DEPENSE], true) ? $s : null;
        $liste = $this->operationsDeLaSaison($operations, $saison);
        $recettes = array_sum(array_map(fn (Operation $o) => $o->isRecette() ? $o->getMontant() : 0, $liste));
        $depenses = array_sum(array_map(fn (Operation $o) => $o->isRecette() ? 0 : $o->getMontant(), $liste));

        return $this->render('finances/operations.html.twig', [
            'saison' => $saison,
            'saisons' => self::saisons(),
            'sens' => $sens,
            'operations' => $sens ? array_values(array_filter($liste, fn (Operation $o) => $o->getSens() === $sens)) : $liste,
            'recettes' => $recettes,
            'depenses' => $depenses,
        ]);
    }

    #[IsGranted('ROLE_FINANCES')]
    #[Route('/operations/nouvelle', name: 'app_finances_operation_nouvelle', methods: ['GET', 'POST'])]
    public function nouvelleOperation(Request $request): Response
    {
        $sens = Operation::RECETTE === $request->query->getString('sens') ? Operation::RECETTE : Operation::DEPENSE;
        $operation = (new Operation())->setSens($sens)->setDate(new \DateTimeImmutable('today'));
        if ('entrees' === $request->query->getString('modele')) {
            $operation->setRubrique('Entrées des matchs');
        }

        return $this->formulaireOperation($request, $operation, Operation::RECETTE === $sens ? 'Nouvelle recette' : 'Nouvelle dépense');
    }

    #[IsGranted('ROLE_FINANCES')]
    #[Route('/operations/{id}/modifier', name: 'app_finances_operation_modifier', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function modifierOperation(Request $request, Operation $operation): Response
    {
        return $this->formulaireOperation($request, $operation, ($operation->isRecette() ? 'Recette' : 'Dépense') . ' du ' . $operation->getDate()?->format('d/m/Y'));
    }

    #[IsGranted('ROLE_FINANCES')]
    #[Route('/operations/{id}/supprimer', name: 'app_finances_operation_supprimer', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function supprimerOperation(Request $request, Operation $operation): Response
    {
        $saison = Rencontre::saisonDe($operation->getDate() ?? new \DateTimeImmutable());
        if ($this->isCsrfTokenValid('supprimer-operation' . $operation->getId(), $request->getPayload()->getString('_token'))) {
            $fichier = $operation->getFichier();
            $this->em->remove($operation);
            $this->em->flush();
            $this->documents->supprimer($fichier);
            $this->addFlash('succes', 'Opération supprimée.');
        }

        return $this->redirectToRoute('app_finances_operations', ['saison' => $saison]);
    }

    #[Route('/operations/{id}/justificatif', name: 'app_finances_justificatif', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function justificatif(Operation $operation): Response
    {
        return $this->fichier($operation->getFichier(), 'Justificatif ' . $operation->getDate()?->format('Y-m-d') . ' ' . $operation->getLibelle());
    }

    // ———————————————————————— Contrats ————————————————————————

    #[Route('/contrats', name: 'app_finances_contrats', methods: ['GET'])]
    public function contrats(Request $request, ContratRepository $contrats): Response
    {
        $etat = \in_array($e = $request->query->getString('etat'), ['actifs', 'termines', 'tous'], true) ? $e : 'actifs';
        $liste = $contrats->findBy([], ['fin' => 'ASC', 'objet' => 'ASC']);
        $liste = array_values(array_filter($liste, fn (Contrat $c) => match ($etat) {
            'actifs' => 'termine' !== $c->getEtat(),
            'termines' => 'termine' === $c->getEtat(),
            default => true,
        }));
        // Sans date de fin : à la fin de la liste.
        usort($liste, fn (Contrat $a, Contrat $b) => [null === $a->getFin(), $a->getFin()] <=> [null === $b->getFin(), $b->getFin()]);

        return $this->render('finances/contrats.html.twig', ['contrats' => $liste, 'etat' => $etat]);
    }

    #[IsGranted('ROLE_FINANCES')]
    #[Route('/contrats/nouveau', name: 'app_finances_contrat_nouveau', methods: ['GET', 'POST'])]
    public function nouveauContrat(Request $request): Response
    {
        return $this->formulaireContrat($request, (new Contrat())->setDebut(new \DateTimeImmutable('today')), 'Nouveau contrat');
    }

    #[IsGranted('ROLE_FINANCES')]
    #[Route('/contrats/{id}/modifier', name: 'app_finances_contrat_modifier', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function modifierContrat(Request $request, Contrat $contrat): Response
    {
        return $this->formulaireContrat($request, $contrat, 'Contrat : ' . $contrat->getObjet());
    }

    #[IsGranted('ROLE_FINANCES')]
    #[Route('/contrats/{id}/supprimer', name: 'app_finances_contrat_supprimer', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function supprimerContrat(Request $request, Contrat $contrat): Response
    {
        if ($this->isCsrfTokenValid('supprimer-contrat' . $contrat->getId(), $request->getPayload()->getString('_token'))) {
            $fichier = $contrat->getFichier();
            $this->em->remove($contrat);
            $this->em->flush();
            $this->documents->supprimer($fichier);
            $this->addFlash('succes', 'Contrat supprimé.');
        }

        return $this->redirectToRoute('app_finances_contrats');
    }

    #[Route('/contrats/{id}/pdf', name: 'app_finances_contrat_pdf', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function contratPdf(Contrat $contrat): Response
    {
        return $this->fichier($contrat->getFichier(), 'Contrat ' . $contrat->getObjet());
    }

    // ———————————————————————— Outils ————————————————————————

    /**
     * Une ligne par joueur : ce qu'il doit (montant particulier, sinon tarif de sa catégorie), ce qu'il a payé, le reste.
     * Saison en cours : tous les joueurs actifs ; autres saisons : ceux qui ont une cotisation enregistrée.
     *
     * @return list<array{joueur: Joueur, cotisation: ?Cotisation, du: float, paye: float, reste: float, statut: string, tarif: ?float}>
     */
    private function lignesCotisations(string $saison, ?Categorie $categorie, JoueurRepository $joueurs, CotisationRepository $cotisations, TarifCotisationRepository $tarifs): array
    {
        $parJoueur = [];
        foreach ($cotisations->findBy(['saison' => $saison]) as $c) {
            $parJoueur[$c->getJoueur()->getId()] = $c;
        }
        $tarifsSaison = [];
        foreach ($tarifs->findBy(['saison' => $saison]) as $t) {
            $tarifsSaison[$t->getCategorie()->getId()] = $t->getMontant();
        }
        $liste = array_map(fn (Cotisation $c) => $c->getJoueur(), $parJoueur);
        if (Rencontre::saisonDe(new \DateTimeImmutable('today')) === $saison) {
            foreach ($joueurs->findBy(['ancien' => false]) as $j) {
                $liste[$j->getId()] ??= $j;
            }
        }
        $lignes = [];
        foreach ($liste as $j) {
            if ($categorie && $j->getCategorie()?->getId() !== $categorie->getId()) {
                continue;
            }
            $c = $parJoueur[$j->getId()] ?? null;
            $tarif = $tarifsSaison[$j->getCategorie()?->getId()] ?? null;
            $du = $c?->getMontant() ?? $tarif ?? 0.0;
            $paye = $c?->getPaye() ?? 0.0;
            $reste = max(0.0, $du - $paye);
            $lignes[] = [
                'joueur' => $j, 'cotisation' => $c, 'tarif' => $tarif, 'du' => $du, 'paye' => $paye, 'reste' => $reste,
                'statut' => match (true) {
                    $du <= 0 && $paye <= 0 => 'sans',
                    $reste <= 0 => 'paye',
                    $paye > 0 => 'partiel',
                    default => 'a_payer',
                },
            ];
        }
        usort($lignes, fn (array $a, array $b) => [$a['joueur']->getCategorie()?->getOrdre() ?? 999, $a['joueur']->getNom(), $a['joueur']->getPrenom()]
            <=> [$b['joueur']->getCategorie()?->getOrdre() ?? 999, $b['joueur']->getNom(), $b['joueur']->getPrenom()]);

        return $lignes;
    }

    /** @return list<Operation> */
    private function operationsDeLaSaison(OperationRepository $operations, string $saison): array
    {
        [$debut, $fin] = Rencontre::bornesSaison($saison);

        return $operations->createQueryBuilder('o')
            ->andWhere('o.date BETWEEN :debut AND :fin')->setParameter('debut', $debut->format('Y-m-d'))->setParameter('fin', $fin->format('Y-m-d'))
            ->orderBy('o.date', 'DESC')->addOrderBy('o.id', 'DESC')
            ->getQuery()->getResult();
    }

    private function formulaireOperation(Request $request, Operation $operation, string $titre): Response
    {
        $form = $this->createForm(OperationType::class, $operation);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            if ($fichier = $form->get('justificatif')->getData()) {
                $operation->setFichier($this->documents->enregistrer($fichier, 'justificatif', $operation->getFichier()));
            }
            $this->em->persist($operation);
            $this->em->flush();
            $this->addFlash('succes', ($operation->isRecette() ? 'Recette' : 'Dépense') . ' de ' . self::euros($operation->getMontant()) . ' € enregistrée.');

            return $this->redirectToRoute('app_finances_operations', ['saison' => Rencontre::saisonDe($operation->getDate())]);
        }
        $vue = ['form' => $form, 'titre' => $titre, 'retour' => $this->generateUrl('app_finances_operations')];
        if ($operation->getId()) {
            $vue['suppression'] = [
                'action' => $this->generateUrl('app_finances_operation_supprimer', ['id' => $operation->getId()]),
                'jeton' => 'supprimer-operation' . $operation->getId(),
                'question' => 'Supprimer cette ' . ($operation->isRecette() ? 'recette' : 'dépense') . ($operation->getFichier() ? ' et son justificatif' : '') . ' ?',
            ];
        }

        return $this->render('commun/formulaire.html.twig', $vue);
    }

    private function formulaireContrat(Request $request, Contrat $contrat, string $titre): Response
    {
        $form = $this->createForm(ContratType::class, $contrat);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            if ($pdf = $form->get('document')->getData()) {
                $contrat->setFichier($this->documents->enregistrer($pdf, 'contrat', $contrat->getFichier()));
            }
            $this->em->persist($contrat);
            $this->em->flush();
            $this->addFlash('succes', 'Contrat enregistré.');

            return $this->redirectToRoute('app_finances_contrats');
        }
        $vue = ['form' => $form, 'titre' => $titre, 'retour' => $this->generateUrl('app_finances_contrats')];
        if ($contrat->getId()) {
            $vue['suppression'] = [
                'action' => $this->generateUrl('app_finances_contrat_supprimer', ['id' => $contrat->getId()]),
                'jeton' => 'supprimer-contrat' . $contrat->getId(),
                'question' => 'Supprimer ce contrat' . ($contrat->getFichier() ? ' et son PDF' : '') . ' ?',
            ];
        }

        return $this->render('commun/formulaire.html.twig', $vue);
    }

    private function fichier(?string $nom, string $titre): Response
    {
        $chemin = $nom ? $this->documents->chemin($nom) : null;
        if (!$chemin) {
            throw $this->createNotFoundException('Aucun fichier joint.');
        }
        $extension = pathinfo($chemin, \PATHINFO_EXTENSION);
        $propre = trim(preg_replace('/[^\p{L}\p{N} ._-]+/u', ' ', $titre) ?? 'document') . '.' . $extension;

        return (new BinaryFileResponse($chemin))
            ->setContentDisposition(ResponseHeaderBag::DISPOSITION_INLINE, $propre, 'document.' . $extension)
            ->setPrivate();
    }

    private function retourCotisations(Request $request, string $saison, Joueur $joueur): Response
    {
        $p = $request->getPayload();

        return $this->redirectToRoute('app_finances_cotisations', array_filter([
            'saison' => $saison,
            'categorie' => ctype_digit($c = $p->getString('filtre_categorie')) ? (int) $c : null,
            'statut' => $p->getString('filtre_statut') ?: null,
            '_fragment' => 'joueur-' . $joueur->getId(),
        ]));
    }

    private function saison(Request $request): string
    {
        return self::saisonValide($request->query->getString('saison'));
    }

    private static function saisonValide(string $saison): string
    {
        return \in_array($saison, self::saisons(), true) ? $saison : Rencontre::saisonDe(new \DateTimeImmutable('today'));
    }

    /** @return list<string> saison suivante, en cours et cinq précédentes */
    private static function saisons(): array
    {
        $annee = (int) substr(Rencontre::saisonDe(new \DateTimeImmutable('today')), 0, 4);

        return array_map(fn (int $a) => $a . '/' . ($a + 1), range($annee + 1, $annee - 5));
    }

    /** « 150 », « 12,50 », « 1 200 » → nombre ; vide ou invalide : null. */
    private static function montant(string $saisie): ?float
    {
        $saisie = str_replace([' ', "\u{a0}", '€', ','], ['', '', '', '.'], trim($saisie));

        return is_numeric($saisie) && (float) $saisie >= 0 && (float) $saisie < 1e8 ? round((float) $saisie, 2) : null;
    }

    private static function euros(?float $montant): string
    {
        return number_format((float) $montant, 2, ',', ' ');
    }
}
