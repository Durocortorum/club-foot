<?php

namespace App\Controller;

use App\Entity\Categorie;
use App\Entity\Classement;
use App\Entity\Rencontre;
use App\Repository\CategorieRepository;
use App\Repository\ClassementRepository;
use App\Repository\RencontreRepository;
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
        // Rappel : matchs joués après le jour de la dernière mise à jour du classement.
        $depuis = $classement ? array_filter($joues, fn (Rencontre $r) => $r->getDate()->format('Y-m-d') > $classement->getMisAJourLe()->format('Y-m-d')) : [];

        return $this->render('championnat/index.html.twig', [
            'categories' => $visibles,
            'categorie' => $categorie,
            'saisons' => $saisons,
            'saison' => $saison,
            'classement' => $classement,
            'lignes' => $this->marquerLeClub($classement?->getLignes() ?? []),
            'apercu' => $apercu ? $this->marquerLeClub($apercu) : null,
            'texte' => $texte,
            'matchs' => $matchs,
            'forme' => \array_slice($joues, -5),
            'prochain' => $prochain,
            'joues_depuis' => \count($depuis),
        ]);
    }

    /** Ajoute « nous » aux lignes du classement : celle(s) du club de l'utilisateur. */
    private function marquerLeClub(array $lignes): array
    {
        return array_map(fn (array $l) => $l + ['nous' => Rencontre::estLeClub($l['equipe'])], $lignes);
    }
}
