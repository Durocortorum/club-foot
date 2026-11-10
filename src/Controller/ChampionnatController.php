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
                if ('' === $dom || '' === $ext || !ctype_digit($bd) || !ctype_digit($be) || $dom === $ext || (int) $bd > 99 || (int) $be > 99) {
                    $erreurs[] = 'ligne ' . ((int) $i + 1);
                    continue;
                }
                $em->persist((new ResultatPoule($categorie, $saison, $date, $dom, $ext, (int) $bd, (int) $be))->setClub($categorie->getClub()));
                ++$ajoutes;
            }
            $em->flush();
            if ($ajoutes) {
                $this->addFlash('succes', \sprintf('%d %s du %s %s : classement recalculé.', $ajoutes, $ajoutes > 1 ? 'résultats' : 'résultat', $date->format('d/m'), $ajoutes > 1 ? 'enregistrés' : 'enregistré'));
            }
            if ($erreurs) {
                $this->addFlash('erreur', 'Incomplet ou incorrect, non enregistré (' . implode(', ', $erreurs) . ') : choisissez deux équipes différentes et un score pour chacune.');
            }
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
