<?php

namespace App\Controller;

use App\Entity\Categorie;
use App\Entity\Joueur;
use App\Entity\ResultatTest;
use App\Entity\TypeTest;
use App\Entity\Utilisateur;
use App\Form\TypeTestType;
use App\Repository\CategorieRepository;
use App\Repository\JoueurRepository;
use App\Security\JoueurVoter;
use App\Service\CatalogueTests;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Tests physiques et techniques : catalogue des tests connus du football (protocole, matériel, âges),
 * séance de tests d'une équipe (tous les résultats saisis dans un tableau), résultat isolé d'un joueur.
 * Voir : droit « Tests physiques (voir) » ; saisir : « Tests physiques (modifier) », pour ses joueurs.
 */
#[IsGranted('ROLE_TESTS_PHYSIQUES_LECTURE')]
class TestController extends AbstractController
{
    public function __construct(
        private readonly CatalogueTests $catalogue,
        private readonly EntityManagerInterface $em,
        private readonly JoueurVoter $voter,
    ) {
    }

    #[Route('/tests', name: 'app_tests', methods: ['GET'])]
    public function index(Request $request, CategorieRepository $categories): Response
    {
        $tests = $this->catalogue->pour($this->club());
        $categorie = ctype_digit($c = $request->query->getString('categorie')) ? $categories->find((int) $c) : null;
        $famille = \in_array($f = $request->query->getString('famille'), TypeTest::FAMILLES, true) ? $f : null;
        $age = $categorie ? CatalogueTests::ageDe($categorie->getNom()) : null;
        $liste = array_filter($tests, fn (TypeTest $t) => (!$famille || $t->getFamille() === $famille) && (null === $age || $t->convientA($age)));
        $parFamille = array_fill_keys(TypeTest::FAMILLES, []);
        foreach ($liste as $t) {
            $parFamille[$t->getFamille()][] = $t;
        }

        return $this->render('test/index.html.twig', [
            'par_famille' => array_filter($parFamille),
            'categories' => $categories->findBy(['secteur' => $this->voter->secteursVisibles() ?: ['-']], ['ordre' => 'ASC']),
            'categorie' => $categorie,
            'famille' => $famille,
            'familles' => TypeTest::FAMILLES,
            'total' => \count($liste),
        ]);
    }

    #[IsGranted('ROLE_TESTS_PHYSIQUES')]
    #[Route('/tests/nouveau', name: 'app_test_type_nouveau', methods: ['GET', 'POST'])]
    public function nouveau(Request $request): Response
    {
        $this->catalogue->pour($this->club());

        return $this->formulaire($request, (new TypeTest())->setClub($this->club())->setOrdre(1000), 'Nouveau test');
    }

    #[IsGranted('ROLE_TESTS_PHYSIQUES')]
    #[Route('/tests/{id}/modifier', name: 'app_test_type_modifier', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function modifier(Request $request, TypeTest $test): Response
    {
        return $this->formulaire($request, $test, 'Modifier « ' . $test . ' »');
    }

    #[IsGranted('ROLE_TESTS_PHYSIQUES')]
    #[Route('/tests/{id}/supprimer', name: 'app_test_type_supprimer', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function supprimer(Request $request, TypeTest $test): Response
    {
        if ($this->isCsrfTokenValid('supprimer-type-test' . $test->getId(), $request->getPayload()->getString('_token'))) {
            $this->em->remove($test);
            $this->em->flush();
            $this->addFlash('succes', 'Test « ' . $test . ' » retiré du catalogue, avec ses résultats.');
        }

        return $this->redirectToRoute('app_tests');
    }

    /**
     * Séance de tests d'une équipe : on choisit l'équipe et les tests, puis on saisit les résultats
     * de tous les joueurs dans un tableau (case vide : joueur absent ou pas testé).
     */
    #[IsGranted('ROLE_TESTS_PHYSIQUES')]
    #[Route('/tests/seance', name: 'app_test_seance', methods: ['GET', 'POST'])]
    public function seance(Request $request, CategorieRepository $categories, JoueurRepository $joueurs): Response
    {
        $tous = $this->catalogue->pour($this->club());
        $donnees = $request->isMethod('POST') ? $request->getPayload() : $request->query;
        $categorie = ctype_digit($c = $donnees->getString('categorie')) ? $categories->find((int) $c) : null;
        $ids = array_map('intval', $donnees->all('tests'));
        $choisis = array_values(array_filter($tous, fn (TypeTest $t) => \in_array($t->getId(), $ids, true)));
        $liste = $categorie ? array_values(array_filter(
            $joueurs->findBy(['categorie' => $categorie, 'ancien' => false], ['nom' => 'ASC', 'prenom' => 'ASC']),
            fn (Joueur $j) => $this->peutSaisir($j),
        )) : [];
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $donnees->getString('date')) ?: new \DateTimeImmutable('today');

        if ($request->isMethod('POST') && $categorie && $choisis && $this->isCsrfTokenValid('seance-tests', $donnees->getString('_token'))) {
            $saisis = 0;
            $valeurs = $donnees->all('valeurs');
            foreach ($liste as $j) {
                foreach ($choisis as $t) {
                    $brut = str_replace([',', ' '], ['.', ''], trim((string) ($valeurs[$j->getId()][$t->getId()] ?? '')));
                    if ('' !== $brut && is_numeric($brut)) {
                        $this->em->persist((new ResultatTest($j, $t, $date, (float) $brut))->setClub($this->club()));
                        ++$saisis;
                    }
                }
            }
            $this->em->flush();
            $this->addFlash($saisis ? 'succes' : 'info', $saisis
                ? \sprintf('%d %s du %s.', $saisis, $saisis > 1 ? 'résultats enregistrés' : 'résultat enregistré', $date->format('d/m/Y'))
                : 'Aucun résultat saisi.');

            return $this->redirectToRoute('app_progression_equipe', ['categorie' => $categorie->getId()]);
        }
        $age = $categorie ? CatalogueTests::ageDe($categorie->getNom()) : null;

        return $this->render('test/seance.html.twig', [
            'categories' => $categories->findBy(['secteur' => $this->voter->secteursModifiables() ?: ['-']], ['ordre' => 'ASC']),
            'categorie' => $categorie,
            'tests' => $tous,
            'conseilles' => null === $age ? [] : array_map(fn (TypeTest $t) => $t->getId(), array_filter($tous, fn (TypeTest $t) => $t->convientA($age))),
            'choisis' => $choisis,
            'joueurs' => $liste,
            'date' => $date,
        ]);
    }

    /** Un résultat isolé pour un joueur (depuis sa fiche). */
    #[IsGranted('ROLE_TESTS_PHYSIQUES')]
    #[Route('/joueurs/{id}/tests/nouveau', name: 'app_test_nouveau', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function resultat(Request $request, Joueur $joueur): Response
    {
        if (!$this->peutSaisir($joueur)) {
            throw $this->createAccessDeniedException();
        }
        $tests = $this->catalogue->pour($this->club());
        $age = CatalogueTests::ageDe($joueur->getCategorie()?->getNom());
        // Conseillés pour son âge d'abord.
        usort($tests, fn (TypeTest $a, TypeTest $b) => [!$a->convientA($age), $a->getOrdre()] <=> [!$b->convientA($age), $b->getOrdre()]);
        $p = $request->getPayload();
        $erreur = null;
        if ($request->isMethod('POST') && $this->isCsrfTokenValid('resultat-test' . $joueur->getId(), $p->getString('_token'))) {
            $test = array_values(array_filter($tests, fn (TypeTest $t) => $t->getId() === $p->getInt('test')))[0] ?? null;
            $brut = str_replace([',', ' '], ['.', ''], trim($p->getString('valeur')));
            $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $p->getString('date')) ?: null;
            if (!$test || !$date || '' === $brut || !is_numeric($brut)) {
                $erreur = 'Choisissez le test, la date et indiquez le résultat (un nombre).';
            } else {
                $this->em->persist((new ResultatTest($joueur, $test, $date, (float) $brut, trim($p->getString('note'))))->setClub($this->club()));
                $this->em->flush();
                $this->addFlash('succes', \sprintf('%s : %s %s enregistré pour %s.', $test, str_replace('.', ',', (string) (float) $brut), $test->getUnite(), $joueur->getPrenom()));

                return $this->redirectToRoute('app_joueur_voir', ['id' => $joueur->getId(), '_fragment' => 'physique']);
            }
        }

        return $this->render('test/resultat.html.twig', [
            'joueur' => $joueur,
            'tests' => $tests,
            'age' => $age,
            'erreur' => $erreur,
            'choisi' => $p->getInt('test') ?: $request->query->getInt('test'),
        ]);
    }

    #[IsGranted('ROLE_TESTS_PHYSIQUES')]
    #[Route('/resultats-tests/{id}/supprimer', name: 'app_resultat_test_supprimer', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function supprimerResultat(Request $request, ResultatTest $resultat): Response
    {
        $joueur = $resultat->getJoueur();
        if (!$this->peutSaisir($joueur)) {
            throw $this->createAccessDeniedException();
        }
        if ($this->isCsrfTokenValid('supprimer-resultat-test' . $resultat->getId(), $request->getPayload()->getString('_token'))) {
            $this->em->remove($resultat);
            $this->em->flush();
            $this->addFlash('succes', 'Résultat supprimé.');
        }

        return $this->redirectToRoute('app_progression_joueur', ['id' => $joueur->getId()]);
    }

    private function formulaire(Request $request, TypeTest $test, string $titre): Response
    {
        $form = $this->createForm(TypeTestType::class, $test);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $this->em->persist($test);
            $this->em->flush();
            $this->addFlash('succes', 'Test « ' . $test . ' » enregistré.');

            return $this->redirect($this->generateUrl('app_tests') . '#test-' . $test->getId());
        }

        return $this->render('commun/formulaire.html.twig', [
            'form' => $form,
            'titre' => $titre,
            'retour' => $this->generateUrl('app_tests'),
        ] + ($test->getId() ? ['suppression' => [
            'action' => $this->generateUrl('app_test_type_supprimer', ['id' => $test->getId()]),
            'jeton' => 'supprimer-type-test' . $test->getId(),
            'question' => 'Retirer « ' . $test . ' » du catalogue ? Tous ses résultats seront supprimés.',
        ]] : []));
    }

    /** Saisir les tests d'un joueur : ses joueurs (fiche modifiable), ou tous avec l'infirmerie. */
    private function peutSaisir(Joueur $joueur): bool
    {
        return $this->isGranted(JoueurVoter::MODIFIER, $joueur) || ($this->isGranted('ROLE_INFIRMERIE') && $this->voter->dansPerimetreMedical($joueur));
    }

    private function club(): \App\Entity\Club
    {
        /** @var Utilisateur $moi */
        $moi = $this->getUser();

        return $moi->getClub();
    }
}
