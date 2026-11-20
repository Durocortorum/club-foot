<?php

namespace App\Controller;

use App\Entity\Categorie;
use App\Entity\Intervenant;
use App\Entity\Joueur;
use App\Entity\ContactMedical;
use App\Entity\Rencontre;
use App\Entity\ResponsableSecteur;
use App\Entity\Sponsor;
use App\Repository\CategorieRepository;
use App\Repository\JoueurRepository;
use App\Repository\ResponsableSecteurRepository;
use App\Security\JoueurVoter;
use App\Service\AccesMedical;
use App\Service\Encadrement;
use Doctrine\ORM\EntityManagerInterface;
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
    public function __construct(
        private readonly JoueurVoter $voter,
        private readonly JoueurRepository $joueurs,
        private readonly CategorieRepository $categories,
        private readonly EntityManagerInterface $em,
        private readonly AccesMedical $medical,
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
            'encadrement' => array_map(fn (string $type) => [
                'type' => $type,
                'libelle' => Encadrement::TYPES[$type]['libelle'],
                'total' => $this->em->getRepository(Encadrement::TYPES[$type]['classe'])->count(['ancien' => false]),
            ], array_keys(Encadrement::TYPES)),
            'exterieurs' => array_map(fn (string $cle) => [
                'cle' => $cle,
                'libelle' => $this->exterieursVisibles()[$cle],
                'total' => 'medical' === $cle
                    ? $this->em->getRepository(ContactMedical::class)->count([])
                    : $this->em->getRepository(Sponsor::class)->count(['ancien' => false]),
            ], array_keys($this->exterieursVisibles())),
            // Droit sur l'infirmerie mais accès médical fermé : les contacts médicaux attendent le code e-mail.
            'medical_a_ouvrir' => $this->isGranted('ROLE_INFIRMERIE_LECTURE') && !$this->medical->estOuvert(),
        ]);
    }

    /**
     * Listing à imprimer ; ?format=csv : le même, en fichier pour Excel.
     * ?qui= joueurs (d'une catégorie avec ?categorie=), un type d'encadrement (educateurs, dirigeants…),
     * encadrement (tous les types) ou tous (joueurs et encadrement).
     */
    #[Route('/listing', name: 'app_joueur_listing', methods: ['GET'])]
    public function listing(Request $request): Response
    {
        $secteurs = $this->voter->secteursVisibles();
        $visibles = $this->categories->findBy(['secteur' => $secteurs], ['ordre' => 'ASC', 'nom' => 'ASC']);
        $qui = $request->query->getString('qui', 'joueurs');
        $exterieurs = $this->exterieursVisibles();
        $choix = ['joueurs', 'encadrement', 'tous', ...array_keys(Encadrement::TYPES), ...array_keys($exterieurs)];
        if ($exterieurs) {
            $choix[] = 'exterieurs';
        }
        if (!\in_array($qui, $choix, true)) {
            $qui = 'joueurs';
        }
        $categorie = null;
        foreach ('joueurs' === $qui ? $visibles : [] as $c) {
            if ($c->getId() === $request->query->getInt('categorie')) {
                $categorie = $c;
            }
        }

        $lignes = [];
        if (\in_array($qui, ['joueurs', 'tous'], true)) {
            foreach ($this->joueurs->actifs($secteurs, $categorie?->getId()) as $j) {
                $lignes[] = $this->ligneJoueur($j);
            }
        }
        foreach (Encadrement::TYPES as $type => $t) {
            if (\in_array($qui, [$type, 'encadrement', 'tous'], true)) {
                foreach ($this->em->getRepository($t['classe'])->findBy(['ancien' => false], ['nom' => 'ASC', 'prenom' => 'ASC']) as $i) {
                    $lignes[] = $this->ligneEncadrement($i, $type, rtrim($t['libelle'], 's'));
                }
            }
        }
        if (\in_array($qui, ['medical', 'exterieurs'], true) && isset($exterieurs['medical'])) {
            foreach ($this->em->getRepository(ContactMedical::class)->findBy([], ['profession' => 'ASC', 'nom' => 'ASC']) as $c) {
                $lignes[] = [
                    'lien' => $this->generateUrl('app_contact_index'),
                    'nom' => $c->getNom(), 'prenom' => $c->getPrenom(), 'groupe' => 'Contact médical', 'fonction' => $c->getProfession(),
                    'ne' => null, 'age' => null, 'adresse' => $c->getAdresse(), 'cp' => $c->getCodePostal(), 'ville' => $c->getVille(),
                    'tels' => [$c->getTelephone(), null, null], 'email' => null, 'licence' => null,
                ];
            }
        }
        if (\in_array($qui, ['partenaires', 'exterieurs'], true) && isset($exterieurs['partenaires'])) {
            foreach ($this->em->getRepository(Sponsor::class)->findBy(['ancien' => false], ['nom' => 'ASC']) as $s) {
                $lignes[] = [
                    'lien' => $this->generateUrl('app_sponsor_voir', ['id' => $s->getId()]),
                    'nom' => $s->getNom(), 'prenom' => null, 'groupe' => 'Partenaire',
                    'fonction' => $s->getType() . ($s->getContact() ? ' · contact : ' . $s->getContact() : ''),
                    'ne' => null, 'age' => null, 'adresse' => $s->getAdresse(), 'cp' => $s->getCodePostal(), 'ville' => $s->getVille(),
                    'tels' => [$s->getTelephone(), null, null], 'email' => $s->getEmail(), 'licence' => null,
                ];
            }
        }
        $titre = match (true) {
            null !== $categorie => (string) $categorie->getNom(),
            'joueurs' === $qui => 'Tous les joueurs',
            'encadrement' === $qui => 'Tout l\'encadrement',
            'tous' === $qui => 'Tout le club',
            'exterieurs' === $qui => 'Extérieurs',
            isset($exterieurs[$qui]) => $exterieurs[$qui],
            default => Encadrement::TYPES[$qui]['libelle'],
        };

        if ('csv' === $request->query->getString('format')) {
            return $this->csv($lignes, $qui, $titre);
        }

        return $this->render('joueur/listing.html.twig', [
            'lignes' => $lignes,
            'qui' => $qui,
            'titre' => $titre,
            'categorie' => $categorie,
            'categories' => $visibles,
            'types' => Encadrement::TYPES,
            'exterieurs' => $exterieurs,
            'telephones' => self::telephones($qui),
        ]);
    }

    /**
     * Listes de personnes extérieures que l'utilisateur peut voir, comme sur leurs pages :
     * contacts médicaux (accès à l'infirmerie, ouvert par le code e-mail comme la page des contacts)
     * et partenaires (administration).
     *
     * @return array<string, string> clé => libellé
     */
    private function exterieursVisibles(): array
    {
        return array_filter([
            'medical' => $this->isGranted('ROLE_INFIRMERIE_LECTURE') && $this->medical->estOuvert() ? 'Contacts médicaux' : null,
            'partenaires' => $this->isGranted('ROLE_ADMIN') ? 'Partenaires' : null,
        ]);
    }

    /** Intitulés des trois téléphones : ceux d'un joueur, de l'encadrement, ou neutres si la liste mélange les deux. */
    private static function telephones(string $qui): array
    {
        return match ($qui) {
            'joueurs' => ['Tél. joueur', 'Tél. parent 1', 'Tél. parent 2'],
            'tous' => ['Téléphone 1', 'Téléphone 2', 'Téléphone 3'],
            'medical', 'partenaires', 'exterieurs' => ['Téléphone'],
            default => ['Portable 1', 'Portable 2', 'Tél. fixe'],
        };
    }

    /** @var array<string, list<string>>|null */
    private ?array $libellesResponsables = null;

    /** @return array<string, mixed> ligne commune du listing */
    private function ligneJoueur(Joueur $j): array
    {
        return [
            'lien' => $this->generateUrl('app_joueur_voir', ['id' => $j->getId()]),
            'nom' => $j->getNom(), 'prenom' => $j->getPrenom(), 'groupe' => (string) $j->getCategorie(), 'fonction' => $j->getPoste(),
            'ne' => $j->getDateNaissance(), 'age' => $j->getAge(),
            'adresse' => $j->getAdresse(), 'cp' => $j->getCodePostal(), 'ville' => $j->getVille(),
            'tels' => [$j->getTelJoueur(), $j->getTelPortable1(), $j->getTelPortable2()],
            'email' => $j->getEmailJoueur() ?: $j->getEmailParent1(), 'licence' => $j->getLicence(),
        ];
    }

    /** @return array<string, mixed> ligne commune du listing */
    private function ligneEncadrement(Intervenant $i, string $type, string $role): array
    {
        // Responsable de secteur (page Catégories) : signalé dans le listing (pastille, et en tête de la fonction dans le CSV).
        $this->libellesResponsables ??= $this->em->getRepository(ResponsableSecteur::class)->libellesParPersonne();
        $secteurs = ResponsableSecteurRepository::secteursDe($this->libellesResponsables, $i->getEmail(), $i->getPrenom(), $i->getNom());

        return [
            'responsable' => array_map(fn (string $s) => 'Responsable secteur ' . $s, $secteurs),
            'lien' => $this->generateUrl('app_intervenant_voir', ['type' => $type, 'id' => $i->getId()]),
            'nom' => $i->getNom(), 'prenom' => $i->getPrenom(), 'groupe' => $role,
            'fonction' => implode(', ', array_filter([$i->getFonction(), $i->getFonction2()])),
            'ne' => $i->getDateNaissance(), 'age' => $i->getDateNaissance()?->diff(new \DateTimeImmutable())->y,
            'adresse' => $i->getAdresse(), 'cp' => $i->getCodePostal(), 'ville' => $i->getVille(),
            'tels' => [$i->getTelPortable1(), $i->getTelPortable2(), $i->getTelFixe()],
            'email' => $i->getEmail(), 'licence' => $i->getLicence(),
        ];
    }

    /** @param list<array<string, mixed>> $lignes */
    private function csv(array $lignes, string $qui, string $titre): Response
    {
        $ligne = fn (array $cellules) => implode(';', array_map(fn ($c) => '"' . str_replace('"', '""', (string) $c) . '"', $cellules));
        $groupe = match ($qui) { 'joueurs' => 'Catégorie', 'tous' => 'Catégorie ou rôle', default => 'Rôle' };
        $telephones = self::telephones($qui);
        $sortie = [$ligne(['Nom', 'Prénom', $groupe, match ($qui) { 'joueurs' => 'Poste', 'tous' => 'Poste ou fonction', default => 'Fonction' }, 'Né le', 'Âge', 'Adresse', 'Code postal', 'Ville',
            ...$telephones, 'E-mail', 'N° de licence'])];
        foreach ($lignes as $l) {
            $sortie[] = $ligne([
                $l['nom'], $l['prenom'], $l['groupe'], implode(', ', array_filter([...($l['responsable'] ?? []), $l['fonction']])), $l['ne']?->format('d/m/Y'), $l['age'],
                $l['adresse'], $l['cp'], $l['ville'], ...\array_slice($l['tels'], 0, \count($telephones)), $l['email'], $l['licence'],
            ]);
        }
        $lignes = $sortie;
        $ascii = strtolower(trim((string) preg_replace('/[^A-Za-z0-9]+/', '-', iconv('UTF-8', 'ASCII//TRANSLIT', $titre) ?: $qui), '-'));
        $nom = 'listing-' . $ascii . '-' . date('Y-m-d') . '.csv';

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
