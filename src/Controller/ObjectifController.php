<?php

namespace App\Controller;

use App\Entity\Joueur;
use App\Entity\ObjectifJoueur;
use App\Entity\ObservationJoueur;
use App\Entity\Rencontre;
use App\Entity\Utilisateur;
use App\Repository\ObjectifJoueurRepository;
use App\Repository\ObservationJoueurRepository;
use App\Security\JoueurVoter;
use App\Service\ProchesJoueur;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Objectifs individuels de progression d'un joueur : objectifs de la saison fixés par l'entraîneur avec leur
 * pourcentage d'atteinte, progression mois par mois, observations (avec vidéo) ; montrés au joueur et à sa famille.
 */
class ObjectifController extends AbstractController
{
    private const MOIS = [1 => 'Janv.', 'Févr.', 'Mars', 'Avril', 'Mai', 'Juin', 'Juil.', 'Août', 'Sept.', 'Oct.', 'Nov.', 'Déc.'];

    public function __construct(
        private readonly ObjectifJoueurRepository $objectifs,
        private readonly ObservationJoueurRepository $observations,
        private readonly EntityManagerInterface $em,
    ) {
    }

    #[Route('/joueurs/{id}/objectifs', name: 'app_objectifs_joueur', requirements: ['id' => '\d+'], methods: ['GET'])]
    #[IsGranted(JoueurVoter::VOIR, 'joueur')]
    public function joueur(Request $request, Joueur $joueur): Response
    {
        $courante = Rencontre::saisonDe(new \DateTimeImmutable('today'));
        $saisons = array_values(array_unique([$courante, ...$this->objectifs->saisons($joueur)]));
        rsort($saisons);
        $saison = \in_array($request->query->getString('saison'), $saisons, true) ? $request->query->getString('saison') : $courante;

        return $this->render('objectif/joueur.html.twig', $this->donnees($joueur, $saison, false) + [
            'saisons' => $saisons,
            'modifiable' => $this->isGranted(JoueurVoter::MODIFIER, $joueur),
            'themes' => ObjectifJoueur::THEMES,
        ]);
    }

    /** Espace famille : objectifs et observations partagées, pour le joueur et les proches qui voient ses statistiques. */
    #[Route('/espace/joueur/{id}/objectifs', name: 'app_espace_objectifs', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function espace(Joueur $joueur, ProchesJoueur $proches): Response
    {
        $moi = $this->getUser();
        if (!$moi instanceof Utilisateur || !$moi->isFamille() || !$proches->voitStatistiques($moi, $joueur)) {
            throw $this->createNotFoundException();
        }

        return $this->render('objectif/espace.html.twig', $this->donnees($joueur, Rencontre::saisonDe(new \DateTimeImmutable('today')), true));
    }

    #[Route('/joueurs/{id}/objectifs/nouveau', name: 'app_objectif_nouveau', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[IsGranted(JoueurVoter::MODIFIER, 'joueur')]
    public function nouveau(Request $request, Joueur $joueur): Response
    {
        $donnees = $request->getPayload();
        $saison = $donnees->getString('saison') ?: Rencontre::saisonDe(new \DateTimeImmutable('today'));
        if ($this->isCsrfTokenValid('objectifs' . $joueur->getId(), $donnees->getString('_token'))) {
            $objectif = new ObjectifJoueur($joueur, preg_match('#^\d{4}/\d{4}$#', $saison) ? $saison : Rencontre::saisonDe(new \DateTimeImmutable('today')));
            $this->remplir($objectif, $request);
            if ('' === $objectif->getTitre()) {
                $this->addFlash('erreur', 'Donnez un intitulé à l\'objectif (ex. : Améliorer ma finition).');
            } else {
                $objectif->setCreeParNom($this->moi());
                $this->em->persist($objectif);
                $this->em->flush();
                $this->addFlash('succes', 'Objectif « ' . $objectif->getTitre() . ' » ajouté.');
            }
        }

        return $this->redirectToRoute('app_objectifs_joueur', ['id' => $joueur->getId(), 'saison' => $saison]);
    }

    /** Modifier un objectif (intitulé, thème, description, échéance) ou seulement son pourcentage d'atteinte. */
    #[Route('/objectifs/{id}/modifier', name: 'app_objectif_modifier', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function modifier(Request $request, ObjectifJoueur $objectif): Response
    {
        $this->denyAccessUnlessGranted(JoueurVoter::MODIFIER, $objectif->getJoueur());
        if ($this->isCsrfTokenValid('objectif' . $objectif->getId(), $request->getPayload()->getString('_token'))) {
            if ($request->getPayload()->has('titre')) {
                $this->remplir($objectif, $request);
            } else {
                $objectif->setProgression($request->getPayload()->getInt('progression'));
            }
            $this->em->flush();
            $this->addFlash('succes', \sprintf('« %s » : %d %%.', $objectif->getTitre(), $objectif->getProgression()));
        }

        return $this->redirectToRoute('app_objectifs_joueur', ['id' => $objectif->getJoueur()->getId(), 'saison' => $objectif->getSaison(), '_fragment' => 'objectif-' . $objectif->getId()]);
    }

    #[Route('/objectifs/{id}/supprimer', name: 'app_objectif_supprimer', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function supprimer(Request $request, ObjectifJoueur $objectif): Response
    {
        $joueur = $objectif->getJoueur();
        $this->denyAccessUnlessGranted(JoueurVoter::MODIFIER, $joueur);
        if ($this->isCsrfTokenValid('supprimer-objectif' . $objectif->getId(), $request->getPayload()->getString('_token'))) {
            $this->em->remove($objectif);
            $this->em->flush();
            $this->addFlash('succes', 'Objectif supprimé.');
        }

        return $this->redirectToRoute('app_objectifs_joueur', ['id' => $joueur->getId(), 'saison' => $objectif->getSaison()]);
    }

    #[Route('/joueurs/{id}/observations/nouvelle', name: 'app_observation_nouvelle', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[IsGranted(JoueurVoter::MODIFIER, 'joueur')]
    public function observation(Request $request, Joueur $joueur): Response
    {
        $donnees = $request->getPayload();
        if ($this->isCsrfTokenValid('objectifs' . $joueur->getId(), $donnees->getString('_token'))) {
            $observation = (new ObservationJoueur($joueur))->setTexte($donnees->getString('texte'))
                ->setPartage($donnees->getBoolean('partage'))->setAuteurNom($this->moi());
            $choisi = $donnees->getString('objectif');
            $objectif = ctype_digit($choisi) ? $this->objectifs->find((int) $choisi) : null;
            $observation->setObjectif($objectif?->getJoueur() === $joueur ? $objectif : null);
            if ($date = \DateTimeImmutable::createFromFormat('!Y-m-d', $donnees->getString('date'))) {
                $observation->setDate($date);
            }
            if ('' === $observation->getTexte()) {
                $this->addFlash('erreur', 'Écrivez l\'observation avant de l\'enregistrer.');
            } elseif (!$observation->setLienVideo($donnees->getString('lien_video'))) {
                $this->addFlash('erreur', 'Lien de la vidéo non valable : collez l\'adresse complète (elle commence par https://).');
            } else {
                $this->em->persist($observation);
                $this->em->flush();
                $this->addFlash('succes', 'Observation enregistrée' . ($observation->isPartage() ? ' et visible par le joueur et sa famille.' : ' (visible seulement par l\'encadrement).'));
            }
        }

        return $this->redirectToRoute('app_objectifs_joueur', ['id' => $joueur->getId(), '_fragment' => 'observations']);
    }

    #[Route('/observations/{id}/supprimer', name: 'app_observation_supprimer', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function supprimerObservation(Request $request, ObservationJoueur $observation): Response
    {
        $joueur = $observation->getJoueur();
        $this->denyAccessUnlessGranted(JoueurVoter::MODIFIER, $joueur);
        if ($this->isCsrfTokenValid('supprimer-observation' . $observation->getId(), $request->getPayload()->getString('_token'))) {
            $this->em->remove($observation);
            $this->em->flush();
            $this->addFlash('succes', 'Observation supprimée.');
        }

        return $this->redirectToRoute('app_objectifs_joueur', ['id' => $joueur->getId(), '_fragment' => 'observations']);
    }

    /**
     * Objectifs de la saison, moyenne d'atteinte, progression mois par mois (moyenne des objectifs à la fin de
     * chaque mois, d'août au mois en cours) et observations de la saison.
     *
     * @return array<string, mixed>
     */
    private function donnees(Joueur $joueur, string $saison, bool $famille): array
    {
        $objectifs = $this->objectifs->duJoueur($joueur, $saison);
        [$debut, $fin] = Rencontre::bornesSaison($saison);
        $aujourdhui = new \DateTimeImmutable('today');
        $mois = [];
        for ($m = $debut->modify('first day of next month'); $m <= $fin && $m <= $aujourdhui; $m = $m->modify('first day of next month')) {
            $finMois = min($m->modify('last day of this month'), $aujourdhui);
            $valeurs = array_filter(array_map(fn (ObjectifJoueur $o) => $o->progressionAu($finMois), $objectifs), fn ($v) => null !== $v);
            $mois[] = ['libelle' => self::MOIS[(int) $m->format('n')], 'valeur' => $valeurs ? (int) round(array_sum($valeurs) / \count($valeurs)) : null];
        }
        $observations = $this->observations->duJoueur($joueur, $debut, $fin->setTime(23, 59), $famille);

        return [
            'joueur' => $joueur,
            'saison' => $saison,
            'objectifs' => $objectifs,
            'moyenne' => $objectifs ? (int) round(array_sum(array_map(fn (ObjectifJoueur $o) => $o->getProgression(), $objectifs)) / \count($objectifs)) : null,
            'mois' => $mois,
            'observations' => $observations,
        ];
    }

    private function remplir(ObjectifJoueur $objectif, Request $request): void
    {
        $d = $request->getPayload();
        $objectif->setTitre($d->getString('titre'))->setTheme($d->getString('theme'))->setDescription($d->getString('description'))
            ->setEcheance(\DateTimeImmutable::createFromFormat('!Y-m-d', $d->getString('echeance')) ?: null);
        if ($d->has('progression') && $d->getInt('progression') !== $objectif->getProgression() || !$objectif->getHistorique()) {
            $objectif->setProgression($d->getInt('progression'));
        }
    }

    private function moi(): ?string
    {
        $moi = $this->getUser();

        return $moi instanceof Utilisateur ? trim($moi->getPrenom() . ' ' . $moi->getNom()) : null;
    }
}
