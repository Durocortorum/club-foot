<?php

namespace App\Controller;

use App\Entity\Categorie;
use App\Entity\Classement;
use App\Entity\Rencontre;
use App\Entity\ResultatPoule;
use App\Repository\CategorieRepository;
use App\Repository\ClassementRepository;
use App\Repository\RencontreRepository;
use App\Repository\ResultatPouleRepository;
use App\Service\CalculClassement;
use App\Security\ActiviteVoter;
use App\Service\ImportClassement;
use App\Service\ImportMatchs;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Championnat d'une équipe pour une saison : classement (recopié de la FFF ou de Transfermarkt),
 * forme sur les 5 derniers matchs, prochain match et matchs de championnat de la saison.
 */
class ChampionnatController extends AbstractController
{
    public function __construct(private readonly ActiviteVoter $activite)
    {
    }

    #[Route('/championnat', name: 'app_championnat', methods: ['GET', 'POST'])]
    public function index(
        Request $request,
        CategorieRepository $categories,
        RencontreRepository $rencontres,
        ClassementRepository $classements,
        ImportClassement $import,
        EntityManagerInterface $em,
        ResultatPouleRepository $resultatsPoule,
        CalculClassement $calcul,
    ): Response {
        $secteurs = $this->activite->secteursMatchs();
        $visibles = null === $secteurs ? $categories->findBy([], ['ordre' => 'ASC']) : $categories->findBy(['secteur' => $secteurs], ['ordre' => 'ASC']);
        if (!$visibles) {
            throw $this->createAccessDeniedException();
        }
        $donnees = $request->isMethod('POST') ? $request->getPayload() : $request->query;
        $categorie = array_values(array_filter($visibles, fn (Categorie $c) => $c->getId() === (int) $donnees->getString('categorie')))[0] ?? $visibles[0];
        $saisons = $rencontres->saisons();
        $saison = \in_array($s = $donnees->getString('saison'), $saisons, true) ? $s : Rencontre::saisonDe(new \DateTimeImmutable('today'));
        $classement = $classements->findOneBy(['categorie' => $categorie, 'saison' => $saison]);

        // Mise à jour du classement : lecture du texte collé (aperçu), puis enregistrement.
        $texte = $request->isMethod('POST') ? $donnees->getString('texte') : '';
        $apercu = null;
        if ($request->isMethod('POST') && $this->isCsrfTokenValid('classement', $donnees->getString('_token'))) {
            $apercu = $import->analyser($texte);
            if (!$apercu) {
                $this->addFlash('erreur', 'Classement non reconnu : copiez toute la page « Classement » de la FFF (ou le tableau de Transfermarkt), avec les équipes et leurs colonnes Pts, J., G., N., P.…');
            } elseif ('enregistrer' === $donnees->getString('action')) {
                $classement ??= (new Classement($categorie, $saison))->setClub($categorie->getClub());
                $em->persist($classement->setLignes($apercu));
                $em->flush();
                $this->addFlash('succes', \sprintf('Classement %s enregistré (%d équipes).', $categorie, \count($apercu)));

                return $this->redirectToRoute('app_championnat', ['categorie' => $categorie->getId(), 'saison' => $saison]);
            }
        }

        // Matchs de championnat de l'équipe pour la saison.
        [$debut, $fin] = Rencontre::bornesSaison($saison);
        $matchs = $rencontres->createQueryBuilder('r')
            ->andWhere('r.categorie = :c')->setParameter('c', $categorie)
            ->andWhere('r.competition = :championnat')->setParameter('championnat', 'Championnat')
            ->andWhere('r.date BETWEEN :debut AND :fin')->setParameter('debut', $debut->format('Y-m-d'))->setParameter('fin', $fin->format('Y-m-d'))
            ->orderBy('r.date')
            ->getQuery()->getResult();
        $joues = array_values(array_filter($matchs, fn (Rencontre $r) => null !== $r->getResultat()));
        $aujourdhui = new \DateTimeImmutable('today');
        $prochain = array_values(array_filter($matchs, fn (Rencontre $r) => null === $r->getResultat() && $r->getDate() >= $aujourdhui))[0] ?? null;
        // Classement recalculé : l'officiel + les matchs joués depuis (ceux du club et les scores saisis des autres).
        $resultats = $resultatsPoule->findBy(['categorie' => $categorie, 'saison' => $saison], ['date' => 'DESC', 'id' => 'ASC']);
        if (!$classement && $resultats) {
            $classement = (new Classement($categorie, $saison))->setClub($categorie->getClub());
        }
        $recalcul = $classement ? $calcul->calculer($classement, $resultats, $matchs) : null;
        $classement?->setCalcule($recalcul['lignes'] ?? null);
        // Rappel : matchs joués après le jour de la dernière mise à jour du classement.
        // … tant qu'aucun score des autres équipes n'a été saisi depuis (nos matchs, eux, sont déjà comptés dans le recalcul).
        $autresSaisis = $classement?->getMisAJourLe() && array_filter($resultats, fn (ResultatPoule $r) => $r->getDate()->format('Y-m-d') > $classement->getMisAJourLe()->format('Y-m-d'));
        $depuis = $classement?->getMisAJourLe() && !$autresSaisis ? array_filter($joues, fn (Rencontre $r) => $r->getDate()->format('Y-m-d') > $classement->getMisAJourLe()->format('Y-m-d')) : [];

        return $this->render('championnat/index.html.twig', [
            'categories' => $visibles,
            'categorie' => $categorie,
            'saisons' => $saisons,
            'saison' => $saison,
            'classement' => $classement,
            'lignes' => $this->marquerLeClub($classement?->getLignesAffichees() ?? []),
            'officiel' => $classement?->getLignes() ?? [],
            'recalcul' => $recalcul,
            'resultats' => $resultats,
            'journees' => self::journees($resultats, $matchs),
            'equipes' => $this->equipesDeLaPoule($classement, $resultats),
            'apercu' => $apercu ? $this->marquerLeClub($apercu) : null,
            'texte' => $texte,
            'matchs' => $matchs,
            'forme' => \array_slice($joues, -5),
            'prochain' => $prochain,
            'joues_depuis' => \count($depuis),
        ]);
    }

    /** Scores des autres matchs de la poule, saisis par journée (lignes laissées vides ignorées). */
    #[Route('/championnat/resultats', name: 'app_championnat_resultats', methods: ['POST'])]
    public function resultats(Request $request, CategorieRepository $categories, EntityManagerInterface $em): Response
    {
        [$categorie, $saison] = $this->equipeEtSaison($request, $categories);
        $p = $request->getPayload();
        if ($this->isCsrfTokenValid('resultats-poule', $p->getString('_token'))) {
            $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $p->getString('date')) ?: new \DateTimeImmutable('today');
            $ajoutes = 0;
            $erreurs = [];
            foreach ($p->all('matchs') as $i => $m) {
                $dom = trim((string) ($m['domicile'] ?? ''));
                $ext = trim((string) ($m['exterieur'] ?? ''));
                $bd = trim((string) ($m['bd'] ?? ''));
                $be = trim((string) ($m['be'] ?? ''));
                if ('' === $dom && '' === $ext && '' === $bd && '' === $be) {
                    continue;
                }
                $sansScore = '' === $bd && '' === $be;
                if ('' === $dom || '' === $ext || $dom === $ext || (!$sansScore && (!ctype_digit($bd) || !ctype_digit($be) || (int) $bd > 99 || (int) $be > 99))) {
                    $erreurs[] = 'ligne ' . ((int) $i + 1);
                    continue;
                }
                $em->persist((new ResultatPoule($categorie, $saison, $date, $dom, $ext, $sansScore ? null : (int) $bd, $sansScore ? null : (int) $be))
                    ->setJournee(ctype_digit($n = trim($p->getString('journee'))) ? ((int) $n ?: null) : null)->setClub($categorie->getClub()));
                ++$ajoutes;
            }
            $em->flush();
            if ($ajoutes) {
                $this->addFlash('succes', \sprintf('%d %s du %s %s : classement recalculé.', $ajoutes, $ajoutes > 1 ? 'matchs' : 'match', $date->format('d/m'), $ajoutes > 1 ? 'enregistrés' : 'enregistré'));
            }
            if ($erreurs) {
                $this->addFlash('erreur', 'Incomplet ou incorrect, non enregistré (' . implode(', ', $erreurs) . ') : choisissez deux équipes différentes, et les deux scores ou aucun.');
            }
        }

        return $this->retour($categorie, $saison, 'resultats-poule');
    }

    /**
     * Une seule case pour tout ce qui vient de la FFF : le site reconnaît ce qui est collé.
     * – un classement : il remplace le classement officiel ;
     * – des matchs (page de l'équipe ou page de la poule) : ceux du club vont dans « Matchs » (sans doublon,
     *   le score est ajouté s'il manquait), ceux des autres équipes dans le calendrier de la poule.
     */
    #[Route('/championnat/importer', name: 'app_championnat_importer', methods: ['POST'])]
    public function importer(
        Request $request,
        CategorieRepository $categories,
        ClassementRepository $classements,
        RencontreRepository $rencontres,
        ResultatPouleRepository $resultats,
        ImportClassement $importClassement,
        ImportMatchs $importMatchs,
        EntityManagerInterface $em,
    ): Response {
        [$categorie, $saison] = $this->equipeEtSaison($request, $categories);
        $p = $request->getPayload();
        if (!$this->isCsrfTokenValid('importer-fff', $p->getString('_token'))) {
            return $this->retour($categorie, $saison, 'importer');
        }
        $texte = $p->getString('texte');
        $bilan = [];

        // Classement : reconnu à ses colonnes (points, joués, gagnés…), qui doivent toutes tenir debout.
        if ($lignes = $importClassement->analyser($texte)) {
            $classement = $classements->findOneBy(['categorie' => $categorie, 'saison' => $saison])
                ?? (new Classement($categorie, $saison))->setClub($categorie->getClub());
            $em->persist($classement->setLignes($lignes));
            $bilan[] = \sprintf('classement enregistré (%d équipes)', \count($lignes));
        } else {
            // Matchs (une page de classement n'en contient pas : on ne la relit pas comme des matchs).
            $lus = $importMatchs->analyser($texte)['matchs'];
            [$debut, $fin] = Rencontre::bornesSaison($saison);
            $lus = array_values(array_filter($lus, fn (array $m) => $m['date'] >= $debut && $m['date'] <= $fin));
            $nom = mb_strtoupper((string) $categorie->getClub()->getNom());
            $club = ['ajoutes' => 0, 'scores' => 0];
            $poule = ['ajoutes' => 0, 'scores' => 0];
            $existants = $resultats->findBy(['categorie' => $categorie, 'saison' => $saison]);
            foreach ($lus as $m) {
                $domicileClub = Rencontre::estLeClub($m['domicile']);
                if ($domicileClub || Rencontre::estLeClub($m['exterieur'])) {
                    $this->matchDuClub($m, $domicileClub, $nom, $categorie, $rencontres, $importMatchs, $em, $club);
                } elseif (!\in_array($m['competition'], ['Amical', 'Coupe', 'Tournoi'], true)) {
                    $this->matchDeLaPoule($m, $categorie, $saison, $existants, $em, $poule);
                }
            }
            if ($club['ajoutes'] || $club['scores']) {
                $bilan[] = \sprintf('vos matchs : %d %s, %d %s', $club['ajoutes'], $club['ajoutes'] > 1 ? 'ajoutés' : 'ajouté', $club['scores'], $club['scores'] > 1 ? 'scores ajoutés' : 'score ajouté');
            }
            if ($poule['ajoutes'] || $poule['scores']) {
                $bilan[] = \sprintf('matchs de la poule : %d %s, %d %s', $poule['ajoutes'], $poule['ajoutes'] > 1 ? 'ajoutés' : 'ajouté', $poule['scores'], $poule['scores'] > 1 ? 'scores mis à jour' : 'score mis à jour');
            }
            if (!$bilan) {
                $this->addFlash($lus ? 'info' : 'erreur', $lus
                    ? 'Rien de nouveau : ces matchs et leurs scores étaient déjà enregistrés.'
                    : 'Rien de reconnu : sur la FFF, ouvrez la page « Classement » ou la page des matchs (de l\'équipe ou de la poule), faites Ctrl+A puis Ctrl+C, et collez ici.');
            }
        }
        $em->flush();
        if ($bilan) {
            $this->addFlash('succes', ucfirst(implode(' · ', $bilan)) . '.');
        }

        return $this->retour($categorie, $saison, 'importer');
    }

    /** Match du club : créé s'il n'existe pas ce jour-là, sinon seulement son score ajouté s'il manquait. */
    private function matchDuClub(array $m, bool $aDomicile, string $nom, Categorie $categorie, RencontreRepository $rencontres, ImportMatchs $import, EntityManagerInterface $em, array &$compte): void
    {
        if ($existant = $rencontres->findOneBy(['categorie' => $categorie, 'date' => $m['date']])) {
            if (null !== $m['butsDomicile'] && null === $existant->getButsDomicile() && null === $existant->getButsExterieur()) {
                $existant->setButsDomicile($m['butsDomicile'])->setButsExterieur($m['butsExterieur']);
                ++$compte['scores'];
            }

            return;
        }
        $adversaire = $import->sansCategorie($aDomicile ? $m['exterieur'] : $m['domicile']);
        $em->persist((new Rencontre())->setClub($categorie->getClub())->setCategorie($categorie)->setDate($m['date'])->setHeure($m['heure'])
            ->setCompetition($m['competition'] ?? 'Championnat')
            ->setDomicile($aDomicile ? $nom : $adversaire)->setExterieur($aDomicile ? $adversaire : $nom)
            ->setButsDomicile($m['butsDomicile'])->setButsExterieur($m['butsExterieur']));
        ++$compte['ajoutes'];
    }

    /**
     * Match entre deux autres équipes : même match = mêmes équipes et même journée (ou même date si la journée
     * n'est pas connue) ; il reçoit alors seulement son score.
     *
     * @param list<ResultatPoule> $existants
     */
    private function matchDeLaPoule(array $m, Categorie $categorie, string $saison, array &$existants, EntityManagerInterface $em, array &$compte): void
    {
        foreach ($existants as $e) {
            if ($e->getDomicile() === $m['domicile'] && $e->getExterieur() === $m['exterieur']
                && (null !== $m['journee'] && null !== $e->getJournee() ? $e->getJournee() === $m['journee'] : $e->getDate() == $m['date'])) {
                $e->setDate($m['date'])->setJournee($m['journee'] ?? $e->getJournee());
                if (null !== $m['butsDomicile'] && [$m['butsDomicile'], $m['butsExterieur']] !== [$e->getButsDomicile(), $e->getButsExterieur()]) {
                    $e->setScore($m['butsDomicile'], $m['butsExterieur']);
                    ++$compte['scores'];
                }

                return;
            }
        }
        $nouveau = (new ResultatPoule($categorie, $saison, $m['date'], $m['domicile'], $m['exterieur'], $m['butsDomicile'], $m['butsExterieur']))
            ->setJournee($m['journee'])->setClub($categorie->getClub());
        $em->persist($nouveau);
        $existants[] = $nouveau;
        ++$compte['ajoutes'];
    }

    /** Scores d'une journée tapés dans les cases (deux cases vides : match pas encore joué). */
    #[Route('/championnat/poule/scores', name: 'app_championnat_poule_scores', methods: ['POST'])]
    public function scoresPoule(Request $request, CategorieRepository $categories, ResultatPouleRepository $resultats, EntityManagerInterface $em): Response
    {
        [$categorie, $saison] = $this->equipeEtSaison($request, $categories);
        $p = $request->getPayload();
        if ($this->isCsrfTokenValid('scores-poule', $p->getString('_token'))) {
            $changes = 0;
            foreach ($p->all('scores') as $id => $score) {
                $match = $resultats->findOneBy(['id' => (int) $id, 'categorie' => $categorie]);
                if (!$match) {
                    continue;
                }
                $nombre = fn ($v) => ctype_digit(trim((string) $v)) && (int) $v <= 99 ? (int) $v : null;
                [$bd, $be] = [$nombre($score['bd'] ?? ''), $nombre($score['be'] ?? '')];
                if ([$bd, $be] !== [$match->getButsDomicile(), $match->getButsExterieur()]) {
                    $match->setScore($bd, $be);
                    ++$changes;
                }
            }
            $em->flush();
            $this->addFlash('succes', $changes ? \sprintf('%d %s : classement recalculé.', $changes, $changes > 1 ? 'scores enregistrés' : 'score enregistré') : 'Aucun score modifié.');
        }

        return $this->retour($categorie, $saison, 'resultats-poule');
    }

    #[Route('/championnat/resultats/{id}/supprimer', name: 'app_championnat_resultat_supprimer', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function supprimerResultat(Request $request, ResultatPoule $resultat, EntityManagerInterface $em): Response
    {
        $categorie = $resultat->getCategorie();
        $this->verifierEquipe($categorie);
        if ($this->isCsrfTokenValid('supprimer-resultat' . $resultat->getId(), $request->getPayload()->getString('_token'))) {
            $em->remove($resultat);
            $em->flush();
            $this->addFlash('succes', 'Résultat supprimé : classement recalculé.');
        }

        return $this->retour($categorie, $resultat->getSaison(), 'resultats-poule');
    }

    /** Points retirés ou ajoutés à une équipe (pénalité, forfait), ou correction annulée. */
    #[Route('/championnat/ajustement', name: 'app_championnat_ajustement', methods: ['POST'])]
    public function ajustement(Request $request, CategorieRepository $categories, ClassementRepository $classements, EntityManagerInterface $em): Response
    {
        [$categorie, $saison] = $this->equipeEtSaison($request, $categories);
        $p = $request->getPayload();
        $classement = $classements->findOneBy(['categorie' => $categorie, 'saison' => $saison]);
        $equipe = trim($p->getString('equipe'));
        if ($classement && '' !== $equipe && $this->isCsrfTokenValid('ajustement', $p->getString('_token'))) {
            if ($p->getBoolean('retirer')) {
                $classement->retirerAjustement($equipe);
                $this->addFlash('succes', 'Correction de points annulée pour ' . $equipe . '.');
            } elseif (0 !== ($points = $p->getInt('points'))) {
                $classement->ajuster($equipe, $points);
                $this->addFlash('succes', \sprintf('%s : %+d %s.', $equipe, $points, abs($points) > 1 ? 'points' : 'point'));
            }
            $em->flush();
        }

        return $this->retour($categorie, $saison, 'resultats-poule');
    }

    /** @return array{0: Categorie, 1: string} */
    private function equipeEtSaison(Request $request, CategorieRepository $categories): array
    {
        $categorie = $categories->find($request->getPayload()->getInt('categorie')) ?? throw $this->createNotFoundException();
        $this->verifierEquipe($categorie);
        $saison = $request->getPayload()->getString('saison');
        if (!preg_match('/^\d{4}\/\d{4}$/', $saison)) {
            throw $this->createNotFoundException();
        }

        return [$categorie, $saison];
    }

    private function verifierEquipe(Categorie $categorie): void
    {
        $secteurs = $this->activite->secteursMatchs();
        if (null !== $secteurs && !\in_array($categorie->getSecteur(), $secteurs, true)) {
            throw $this->createAccessDeniedException();
        }
    }

    private function retour(Categorie $categorie, string $saison, string $ancre): Response
    {
        return $this->redirectToRoute('app_championnat', ['categorie' => $categorie->getId(), 'saison' => $saison, '_fragment' => $ancre]);
    }

    /**
     * Matchs de la poule regroupés par journée (numéro FFF, sinon week-end), du plus ancien au plus récent ;
     * le match du club de ce week-end-là y est ajouté (lecture seule : il se modifie depuis « Matchs »).
     *
     * @param list<ResultatPoule> $resultats
     * @param list<Rencontre>     $matchsClub
     *
     * @return list<array{numero: int|null, date: \DateTimeImmutable, matchs: list<ResultatPoule>, club: list<Rencontre>, joues: int}>
     */
    private static function journees(array $resultats, array $matchsClub): array
    {
        $groupes = [];
        foreach ($resultats as $r) {
            // Sans numéro : regroupé par week-end (le lundi de la semaine).
            $cle = null !== $r->getJournee() ? 'J' . $r->getJournee() : 'S' . $r->getDate()->modify('monday this week')->format('Y-m-d');
            $groupes[$cle] ??= ['numero' => $r->getJournee(), 'date' => $r->getDate(), 'matchs' => [], 'club' => [], 'joues' => 0];
            $groupes[$cle]['matchs'][] = $r;
            $groupes[$cle]['date'] = min($groupes[$cle]['date'], $r->getDate());
            $groupes[$cle]['joues'] += (int) $r->isJoue();
        }
        foreach ($matchsClub as $m) {
            // Rangé dans la journée de la même semaine, ou dans une journée à lui s'il n'y en a pas.
            $semaine = $m->getDate()->modify('monday this week');
            $cle = null;
            foreach ($groupes as $c => $g) {
                if ($g['date']->modify('monday this week') == $semaine) {
                    $cle = $c;
                    break;
                }
            }
            $cle ??= 'S' . $semaine->format('Y-m-d');
            $groupes[$cle] ??= ['numero' => null, 'date' => $m->getDate(), 'matchs' => [], 'club' => [], 'joues' => 0];
            $groupes[$cle]['club'][] = $m;
        }
        $groupes = array_values($groupes);
        usort($groupes, fn (array $a, array $b) => [$a['date'], $a['numero']] <=> [$b['date'], $b['numero']]);
        foreach ($groupes as &$g) {
            usort($g['matchs'], fn (ResultatPoule $a, ResultatPoule $b) => [$a->getDate(), $a->getDomicile()] <=> [$b->getDate(), $b->getDomicile()]);
        }

        return $groupes;
    }

    /**
     * Équipes proposées pour saisir un score : celles du classement et des scores déjà saisis, sauf le club
     * (ses matchs viennent de ses feuilles de match).
     *
     * @param list<ResultatPoule> $resultats
     *
     * @return list<string>
     */
    private function equipesDeLaPoule(?Classement $classement, array $resultats): array
    {
        $noms = array_column($classement?->getLignes() ?? [], 'equipe');
        foreach ($resultats as $r) {
            $noms[] = $r->getDomicile();
            $noms[] = $r->getExterieur();
        }
        $noms = array_values(array_unique(array_filter($noms, fn (string $n) => !Rencontre::estLeClub($n))));
        sort($noms);

        return $noms;
    }

    /** Ajoute « nous » aux lignes du classement : celle(s) du club de l'utilisateur. */
    private function marquerLeClub(array $lignes): array
    {
        return array_map(fn (array $l) => $l + ['nous' => Rencontre::estLeClub($l['equipe'])], $lignes);
    }
}
