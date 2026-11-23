<?php

namespace App\Controller;

use App\Entity\Categorie;
use App\Entity\EtatForme;
use App\Entity\Joueur;
use App\Entity\Utilisateur;
use App\Repository\CategorieRepository;
use App\Repository\EtatFormeRepository;
use App\Repository\JoueurRepository;
use App\Security\JoueurVoter;
use App\Service\AccesFamille;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * État de forme des joueurs : chaque joueur (ou sa famille) renseigne chaque jour sa fatigue, son sommeil, ses
 * douleurs et sa motivation ; l'éducateur voit son groupe du jour (en forme, à surveiller, fatigués) et les 7 derniers
 * jours, pour adapter la séance et prévenir les blessures.
 */
class EtatFormeController extends AbstractController
{
    #[Route('/etat-forme', name: 'app_etat_forme', methods: ['GET'])]
    public function groupe(Request $request, CategorieRepository $categories, JoueurVoter $voter, JoueurRepository $joueurs, EtatFormeRepository $etats): Response
    {
        $visibles = $categories->findBy(['secteur' => $voter->secteursVisibles() ?: ['-']], ['ordre' => 'ASC']);
        if (!$visibles) {
            throw $this->createAccessDeniedException();
        }
        $id = $request->query->getString('categorie');
        $categorie = array_values(array_filter($visibles, fn (Categorie $c) => ctype_digit($id) && $c->getId() === (int) $id))[0] ?? $visibles[0];
        $aujourdhui = new \DateTimeImmutable('today');
        $jour = \DateTimeImmutable::createFromFormat('!Y-m-d', $request->query->getString('jour')) ?: $aujourdhui;
        $jour = min($jour, $aujourdhui);
        $effectif = $joueurs->findBy(['categorie' => $categorie, 'ancien' => false], ['nom' => 'ASC', 'prenom' => 'ASC']);
        $jours = array_map(fn (int $k) => $jour->modify("-$k days"), range(6, 0));
        $parJoueur = $etats->parJoueurEtJour($effectif, $jours[0], $jour);
        $cle = $jour->format('Y-m-d');

        $lignes = [];
        $compte = ['forme' => 0, 'surveiller' => 0, 'fatigue' => 0, 'sans' => 0];
        foreach ($effectif as $j) {
            $etat = $parJoueur[$j->getId()][$cle] ?? null;
            ++$compte[$etat ? $etat->getEtat() : 'sans'];
            $lignes[] = ['joueur' => $j, 'etat' => $etat, 'semaine' => array_map(fn (\DateTimeImmutable $d) => $parJoueur[$j->getId()][$d->format('Y-m-d')] ?? null, $jours)];
        }
        // Les plus préoccupants d'abord : fatigués, à surveiller, en forme, puis sans réponse.
        $ordre = ['fatigue' => 0, 'surveiller' => 1, 'forme' => 2];
        usort($lignes, fn (array $a, array $b) => [$a['etat'] ? $ordre[$a['etat']->getEtat()] : 3, $a['joueur']->getNom()] <=> [$b['etat'] ? $ordre[$b['etat']->getEtat()] : 3, $b['joueur']->getNom()]);

        return $this->render('etat_forme/groupe.html.twig', [
            'categories' => $visibles,
            'categorie' => $categorie,
            'jour' => $jour,
            'precedent' => $jour->modify('-1 day'),
            'suivant' => $jour < $aujourdhui ? $jour->modify('+1 day') : null,
            'jours' => $jours,
            'lignes' => $lignes,
            'compte' => $compte,
            'criteres' => EtatForme::CRITERES,
        ]);
    }

    /** Espace famille : le joueur (ou ses parents) renseigne son état de forme du jour ; corrigeable le jour même. */
    #[Route('/espace/etat-forme/{joueur}', name: 'app_espace_etat_forme', requirements: ['joueur' => '\d+'], methods: ['POST'])]
    public function renseigner(Request $request, Joueur $joueur, EtatFormeRepository $etats, EntityManagerInterface $em, AccesFamille $acces): Response
    {
        $moi = $this->getUser();
        $p = $request->getPayload();
        if (!$moi instanceof Utilisateur || !$moi->isFamille() || !$moi->suit($joueur) || !$this->isCsrfTokenValid('etat-forme' . $joueur->getId(), $p->getString('_token'))) {
            throw $this->createAccessDeniedException();
        }
        $aujourdhui = new \DateTimeImmutable('today');
        $etat = $etats->duJour($joueur, $aujourdhui) ?? new EtatForme($joueur, $aujourdhui);
        $valeurs = [];
        foreach (array_keys(EtatForme::CRITERES) as $c) {
            $valeurs[$c] = $p->getString($c);
        }
        $etat->renseigner($valeurs, $p->getString('commentaire'), $acces->estLeJoueur($moi, $joueur) ? 'Joueur' : 'Parent : ' . trim($moi->getPrenom() . ' ' . $moi->getNom()));
        $em->persist($etat);
        $em->flush();
        $this->addFlash('succes', \sprintf('État de forme de %s enregistré : %s. Merci !', $joueur->getPrenom(), mb_strtolower($etat->getEtatLibelle())));

        return $this->redirect($this->generateUrl('app_espace') . '#etat-forme-' . $joueur->getId());
    }
}
