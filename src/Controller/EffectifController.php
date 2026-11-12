<?php

namespace App\Controller;

use App\Entity\Categorie;
use App\Entity\Joueur;
use App\Entity\Rencontre;
use App\Repository\CategorieRepository;
use App\Repository\JoueurRepository;
use App\Security\JoueurVoter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Effectifs du club : nombre de joueurs actifs par catégorie et par année de naissance,
 * et listing des joueurs (tous ou d'une catégorie) à imprimer ou à ouvrir dans Excel.
 * Limité aux secteurs que l'utilisateur peut voir, comme la liste des joueurs.
 */
#[Route('/joueurs')]
class EffectifController extends AbstractController
{
    /** Colonnes du listing et de l'export. */
    private const COLONNES = ['Nom', 'Prénom', 'Catégorie', 'Né le', 'Âge', 'Adresse', 'Code postal', 'Ville',
        'Tél. joueur', 'Tél. parent 1', 'Tél. parent 2', 'E-mail', 'N° de licence'];

    public function __construct(
        private readonly JoueurVoter $voter,
        private readonly JoueurRepository $joueurs,
        private readonly CategorieRepository $categories,
    ) {
    }

    #[Route('/effectifs', name: 'app_joueur_effectifs', methods: ['GET'])]
    public function effectifs(): Response
    {
        $secteurs = $this->voter->secteursVisibles();
        $actifs = $this->joueurs->actifs($secteurs);
        $saison = Rencontre::saisonDe(new \DateTimeImmutable('today'));
        // Catégorie d'âge de la saison : U15 = joueurs qui ont 14 ans au 1er janvier de la fin de saison
        // (saison 2026/2027 : nés en 2012 → U15).
        $finSaison = (int) substr($saison, 5, 4);

        // Par catégorie, regroupées par secteur (toutes les catégories visibles, même vides).
        $parCategorie = [];
        foreach ($this->categories->findBy(['secteur' => $secteurs], ['ordre' => 'ASC', 'nom' => 'ASC']) as $c) {
            $parCategorie[$c->getId()] = ['categorie' => $c, 'joueurs' => []];
        }
        foreach ($actifs as $j) {
            $parCategorie[$j->getCategorie()->getId()]['joueurs'][] = $j;
        }
        $secteursListe = [];
        foreach (Categorie::SECTEURS as $code => $s) {
            $lignes = array_values(array_filter($parCategorie, fn (array $l) => $l['categorie']->getSecteur() === $code));
            if ($lignes) {
                $secteursListe[] = [
                    'libelle' => $s['libelle'],
                    'lignes' => array_map(fn (array $l) => ['categorie' => $l['categorie']] + self::resume($l['joueurs']), $lignes),
                    'total' => array_sum(array_map(fn (array $l) => \count($l['joueurs']), $lignes)),
                ];
            }
        }

        // Par année de naissance, des plus jeunes aux plus âgés.
        $parAnnee = [];
        $sansDate = [];
        foreach ($actifs as $j) {
            if (!$j->getDateNaissance()) {
                $sansDate[] = $j;
                continue;
            }
            $annee = (int) $j->getDateNaissance()->format('Y');
            $parAnnee[$annee] ??= ['annee' => $annee, 'u' => $finSaison - $annee, 'joueurs' => [], 'equipes' => []];
            $parAnnee[$annee]['joueurs'][] = $j;
            $nom = (string) $j->getCategorie();
            $parAnnee[$annee]['equipes'][$nom] = ($parAnnee[$annee]['equipes'][$nom] ?? 0) + 1;
        }
        krsort($parAnnee);
        foreach ($parAnnee as &$ligne) {
            $ages = array_map(fn (Joueur $j) => $j->getAge(), $ligne['joueurs']);
            $ligne['ages'] = [min($ages), max($ages)];
            $ligne['total'] = \count($ligne['joueurs']);
            unset($ligne['joueurs']);
        }
        unset($ligne);

        return $this->render('joueur/effectifs.html.twig', [
            'secteurs' => $secteursListe,
            'par_annee' => array_values($parAnnee),
            'sans_date' => $sansDate,
            'total' => \count($actifs),
            'saison' => $saison,
            'max_annee' => $parAnnee ? max(array_column($parAnnee, 'total')) : 0,
        ]);
    }

    /** Listing à imprimer ; ?format=csv : le même, en fichier pour Excel. */
    #[Route('/listing', name: 'app_joueur_listing', methods: ['GET'])]
    public function listing(Request $request): Response
    {
        $secteurs = $this->voter->secteursVisibles();
        $visibles = $this->categories->findBy(['secteur' => $secteurs], ['ordre' => 'ASC', 'nom' => 'ASC']);
        $categorie = null;
        foreach ($visibles as $c) {
            if ($c->getId() === $request->query->getInt('categorie')) {
                $categorie = $c;
            }
        }
        $liste = $this->joueurs->actifs($secteurs, $categorie?->getId());

        if ('csv' === $request->query->getString('format')) {
            return $this->csv($liste, $categorie);
        }

        return $this->render('joueur/listing.html.twig', [
            'joueurs' => $liste,
            'categorie' => $categorie,
            'categories' => $visibles,
        ]);
    }

    /** @param list<Joueur> $liste */
    private function csv(array $liste, ?Categorie $categorie): Response
    {
        $ligne = fn (array $cellules) => implode(';', array_map(fn ($c) => '"' . str_replace('"', '""', (string) $c) . '"', $cellules));
        $lignes = [$ligne(self::COLONNES)];
        foreach ($liste as $j) {
            $lignes[] = $ligne([
                $j->getNom(), $j->getPrenom(), $j->getCategorie(), $j->getDateNaissance()?->format('d/m/Y'), $j->getAge(),
                $j->getAdresse(), $j->getCodePostal(), $j->getVille(),
                $j->getTelJoueur(), $j->getTelPortable1(), $j->getTelPortable2(),
                $j->getEmailJoueur() ?: $j->getEmailParent1(), $j->getLicence(),
            ]);
        }
        $nom = 'effectif-' . ($categorie ? strtolower((string) preg_replace('/[^A-Za-z0-9]+/', '-', (string) $categorie->getNom())) : 'club') . '-' . date('Y-m-d') . '.csv';

        // BOM UTF-8 et point-virgule : Excel ouvre le fichier directement, accents compris.
        return new Response("\xEF\xBB\xBF" . implode("\r\n", $lignes) . "\r\n", 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="' . $nom . '"',
        ]);
    }

    /**
     * Total, âges extrêmes et moyen, années de naissance et joueurs sans date de naissance d'une liste.
     *
     * @param list<Joueur> $joueurs
     *
     * @return array{total: int, age_moyen: ?float, ages: ?array{0: int, 1: int}, annees: ?array{0: int, 1: int}, sans_date: int}
     */
    private static function resume(array $joueurs): array
    {
        $dates = array_values(array_filter(array_map(fn (Joueur $j) => $j->getDateNaissance(), $joueurs)));
        $ages = array_map(fn (Joueur $j) => $j->getAge(), array_filter($joueurs, fn (Joueur $j) => null !== $j->getDateNaissance()));
        $annees = array_map(fn (\DateTimeImmutable $d) => (int) $d->format('Y'), $dates);

        return [
            'total' => \count($joueurs),
            'age_moyen' => $ages ? array_sum($ages) / \count($ages) : null,
            'ages' => $ages ? [min($ages), max($ages)] : null,
            'annees' => $annees ? [min($annees), max($annees)] : null,
            'sans_date' => \count($joueurs) - \count($dates),
        ];
    }
}
