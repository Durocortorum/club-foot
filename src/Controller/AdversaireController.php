<?php

namespace App\Controller;

use App\Entity\FicheAdversaire;
use App\Entity\Rencontre;
use App\Entity\Utilisateur;
use App\Repository\FicheAdversaireRepository;
use App\Repository\RencontreRepository;
use App\Security\ActiviteVoter;
use App\Service\CompositionTerrain;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Analyse de l'adversaire d'un match, pour l'équipe du match : confrontations passées et bilan, dernière
 * confrontation (score, organisation de l'adversaire, vidéo, composition), notes de l'éducateur et préparation
 * du prochain match contre lui.
 */
class AdversaireController extends AbstractController
{
    #[Route('/matchs/{id}/adversaire', name: 'app_adversaire', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    #[IsGranted(ActiviteVoter::GERER, 'rencontre')]
    public function fiche(Request $request, Rencontre $rencontre, RencontreRepository $rencontres, FicheAdversaireRepository $fiches,
        CompositionTerrain $terrain, EntityManagerInterface $em): Response
    {
        $categorie = $rencontre->getCategorie();
        if ($rencontre->isPlateau() || !$categorie) {
            throw $this->createNotFoundException('Pas d\'adversaire unique pour un plateau.');
        }
        $nom = (string) $rencontre->getAdversaire();
        $cle = FicheAdversaire::cleDe($nom);

        // Tous les matchs de l'équipe contre cet adversaire, du plus ancien au plus récent.
        $matchs = array_values(array_filter($rencontres->findBy(['categorie' => $categorie], ['date' => 'ASC', 'id' => 'ASC']),
            fn (Rencontre $r) => !$r->isPlateau() && FicheAdversaire::cleDe($r->getAdversaire()) === $cle));
        // Joué : score connu, ou fin sifflée dans le Match Live (le score n'est peut-être pas encore remonté).
        $joue = fn (Rencontre $r) => null !== $r->getResultat() || !empty($r->getChronoDirect()['fini']);
        $joues = array_values(array_filter($matchs, $joue));
        $aujourdhui = new \DateTimeImmutable('today');
        $aVenir = array_values(array_filter($matchs, fn (Rencontre $r) => !$joue($r) && $r->getDate() >= $aujourdhui));
        $derniere = $joues ? end($joues) : null;
        $prochain = $aVenir[0] ?? null;

        $fiche = $fiches->pour($categorie, $nom);
        $modifiable = $this->isGranted(ActiviteVoter::MODIFIER_MATCH, $categorie);

        if ($request->isMethod('POST')) {
            if (!$modifiable) {
                throw $this->createAccessDeniedException();
            }
            $donnees = $request->getPayload();
            if ($this->isCsrfTokenValid('adversaire' . $rencontre->getId(), $donnees->getString('_token'))) {
                $fiche ??= (new FicheAdversaire($categorie, $nom));
                $fiche->setNom($nom)
                    ->setPointsForts($donnees->getString('points_forts'))
                    ->setPointsFaibles($donnees->getString('points_faibles'))
                    ->setARetenir($donnees->getString('a_retenir'))
                    ->setPreparation($donnees->getString('preparation'))
                    ->setSystemeHabituel($donnees->getString('systeme_habituel'))
                    ->setJoueursDangereux($donnees->all('joueurs'))
                    ->setVideos($donnees->all('videos'));
                foreach (array_keys(FicheAdversaire::JEU) as $theme) {
                    $fiche->setTheme($theme, $donnees->getString('jeu_' . $theme), $donnees->getInt('niveau_' . $theme));
                }
                $moi = $this->getUser();
                if ($moi instanceof Utilisateur) {
                    $fiche->marquerModifie($moi);
                }
                $em->persist($fiche);

                // Dernière confrontation : organisation de l'adversaire et lien de la vidéo.
                if ($derniere && $this->isGranted(ActiviteVoter::MODIFIER_MATCH, $derniere)) {
                    $systeme = $donnees->getString('systeme_adverse');
                    if ('' === $systeme || \in_array($systeme, array_merge(...array_values(Rencontre::SYSTEMES)), true)) {
                        $derniere->setSystemeAdverse($systeme);
                    }
                    $lien = trim($donnees->getString('lien_video'));
                    if ('' !== $lien && !preg_match('#^https?://#i', $lien)) {
                        $lien = 'https://' . $lien;
                    }
                    if ('' === $lien || filter_var($lien, \FILTER_VALIDATE_URL)) {
                        $derniere->setLienVideo('' === $lien ? null : mb_substr($lien, 0, 255));
                    } else {
                        $this->addFlash('erreur', 'Lien de la vidéo non retenu : collez l\'adresse complète (elle commence par https://).');
                    }
                }
                $em->flush();
                $this->addFlash('succes', 'Analyse de l\'adversaire enregistrée.');
            }

            return $this->redirectToRoute('app_adversaire', ['id' => $rencontre->getId()]);
        }

        // Bilan des confrontations, du point de vue du club.
        $bilan = ['V' => 0, 'N' => 0, 'D' => 0, 'pour' => 0, 'contre' => 0];
        foreach ($joues as $r) {
            if (null === $r->getResultat()) {
                continue;
            }
            ++$bilan[$r->getResultat()];
            $bilan['pour'] += (int) $r->getButsPour();
            $bilan['contre'] += (int) $r->getButsContre();
        }
        // Organisations de l'adversaire notées sur les matchs joués : la plus fréquente d'abord.
        $systemes = array_count_values(array_filter(array_map(fn (Rencontre $r) => $r->getSystemeAdverse(), $joues)));
        arsort($systemes);
        $libelle = $prochain ? $this->libelleProchain($prochain, $joues) : null;

        // Ce que les matchs joués disent déjà : buteurs adverses (numéro de maillot noté sur la feuille) et buts
        // encaissés sur coups de pied arrêtés (situation du but : corner, coup franc, penalty).
        $buteurs = [];
        $cpa = ['total' => 0, 'detail' => []];
        foreach ($joues as $r) {
            foreach ($r->getButs() as $b) {
                if (!$b->isAdverse()) {
                    continue;
                }
                if ($b->getNumeroAdverse()) {
                    $buteurs[$b->getNumeroAdverse()] = ($buteurs[$b->getNumeroAdverse()] ?? 0) + 1;
                }
                if ($s = $b->getSituationCpa()) {
                    ++$cpa['total'];
                    $nomCpa = mb_strtolower(\App\Entity\ActionMatch::SITUATIONS_BUT[$s]);
                    $cpa['detail'][$nomCpa] = ($cpa['detail'][$nomCpa] ?? 0) + 1;
                }
            }
        }
        arsort($buteurs);
        $systemeHabituel = $fiche?->getSystemeHabituel() ?? (array_key_first($systemes) ?: null);

        return $this->render('adversaire/fiche.html.twig', [
            'buteurs_adverses' => $buteurs,
            'cpa_encaisses' => $cpa,
            'systeme_habituel' => $systemeHabituel,
            'systeme_deduit' => !$fiche?->getSystemeHabituel() && $systemeHabituel,
            'places' => FicheAdversaire::positionsSysteme($systemeHabituel),
            'jeu' => FicheAdversaire::JEU,
            'max_joueurs' => FicheAdversaire::MAX_JOUEURS,
            'max_videos' => FicheAdversaire::MAX_VIDEOS,
            'videos_matchs' => array_values(array_filter(array_reverse($joues), fn (Rencontre $r) => $r->getLienVideo())),
            'rencontre' => $rencontre,
            'adversaire' => $nom,
            'fiche' => $fiche,
            'joues' => array_reverse($joues),
            'bilan' => $bilan,
            'systemes' => $systemes,
            'derniere' => $derniere,
            'compo' => $derniere ? $terrain->placer($derniere) : null,
            'prochain' => $prochain,
            'libelle_prochain' => $libelle,
            // « match retour », « match aller », sinon « prochain match ».
            'nom_prochain' => \in_array($libelle, ['Match retour', 'Match aller'], true) ? mb_strtolower($libelle) : 'prochain match',
            'systemes_choix' => Rencontre::SYSTEMES,
            'modifiable' => $modifiable,
            // Arrivée depuis le Match Live à la fin du match : formulaire ouvert.
            'ouvrir' => !$fiche || $request->query->getBoolean('apres'),
            'video_modifiable' => $derniere && $this->isGranted(ActiviteVoter::MODIFIER_MATCH, $derniere),
        ]);
    }

    /**
     * « Match retour » : championnat déjà joué contre cet adversaire dans la même saison ; « Match aller » :
     * championnat sans match joué contre lui cette saison ; sinon la compétition (« Coupe »…).
     *
     * @param list<Rencontre> $joues
     */
    private function libelleProchain(Rencontre $prochain, array $joues): string
    {
        if ('Championnat' !== $prochain->getCompetition()) {
            return $prochain->getCompetition() ?? 'Prochain match';
        }
        foreach ($joues as $r) {
            if ('Championnat' === $r->getCompetition() && $r->getSaison() === $prochain->getSaison()) {
                return 'Match retour';
            }
        }

        return 'Match aller';
    }
}
