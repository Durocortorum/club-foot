<?php

namespace App\Controller;

use App\Entity\AbsenceEntrainement;
use App\Entity\Convoque;
use App\Entity\Entrainement;
use App\Entity\Joueur;
use App\Entity\Rencontre;
use App\Entity\Ressenti;
use App\Entity\Sanction;
use App\Repository\SanctionRepository;
use App\Entity\Stage;
use App\Entity\Tache;
use App\Repository\RessentiRepository;
use App\Repository\StageRepository;
use App\Service\SuiviRessenti;
use App\Repository\TacheRepository;
use App\Service\AvisTaches;
use App\Entity\Utilisateur;
use App\Service\AccesFamille;
use App\Service\ProchesJoueur;
use App\Repository\ConvoqueRepository;
use App\Repository\EvenementRepository;
use App\Repository\ParticipationRepository;
use App\Repository\PresenceRepository;
use App\Service\BilanJoueur;
use App\Service\CalendrierMois;
use App\Repository\JoueurRepository;
use App\Service\PlanningEntrainements;
use App\Repository\RencontreRepository;
use App\Repository\UtilisateurRepository;
use App\Service\ClubCourant;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\RepeatedType;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Validator\Constraints\IsTrue;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;

/**
 * Espace famille : ce que voient un joueur ou ses parents une fois connectés,
 * et l'activation du compte depuis le lien reçu.
 */
class EspaceController extends AbstractController
{
    #[Route('/espace', name: 'app_espace', methods: ['GET'])]
    public function index(
        RencontreRepository $rencontres,
        ConvoqueRepository $convoques,
        PlanningEntrainements $planning,
        AccesFamille $acces,
        ProchesJoueur $proches,
        ParticipationRepository $participations,
        PresenceRepository $presences,
        BilanJoueur $bilan,
        EvenementRepository $evenements,
        TacheRepository $taches,
        StageRepository $stages,
        RessentiRepository $ressentis,
        SuiviRessenti $suiviRessenti,
        SanctionRepository $sanctions,
    ): Response
    {
        /** @var Utilisateur $moi */
        $moi = $this->getUser();
        if (!$moi->isFamille()) {
            // Lien d'un SMS ou d'un e-mail de convocation ouvert avec un compte de l'encadrement.
            $this->addFlash('info', \sprintf(
                'Ce lien mène à l\'espace famille, où les parents répondent aux convocations. Vous êtes connecté avec le compte de l\'encadrement %s : déconnectez-vous puis connectez-vous avec un compte famille pour voir cet espace.',
                $moi->getEmail(),
            ));

            return $this->redirectToRoute('app_accueil');
        }

        $convocations = [];
        foreach ($convoques->aVenir($moi->getJoueurs()) as $c) {
            $convocations[$c->getJoueur()->getId()][] = $c;
        }
        $categories = [];
        foreach ($moi->getJoueurs() as $j) {
            if ($c = $j->getCategorie()) {
                $categories[$c->getId()] = $c;
            }
        }
        // Même période que la page « Planning » de l'éducateur.
        $entrainements = $planning->aVenir(array_values($categories), PlanningEntrainements::HORIZON);

        // Tâches confiées aux joueurs, à faire ou en cours (une tâche faite disparaît).
        $tachesParJoueur = [];
        foreach ($taches->pourJoueurs($moi->getJoueurs()->toArray()) as $t) {
            $tachesParJoueur[$t->getJoueur()->getId()][] = $t;
        }

        // Autoévaluation : ressentis des 14 derniers jours, et séances encore à noter.
        $parJoueur = [];
        foreach ($ressentis->recents($moi->getJoueurs()->toArray(), new \DateTimeImmutable('today -14 days')) as $r) {
            $parJoueur[$r->getJoueur()->getId()][] = $r;
        }

        [$debutSaison] = Rencontre::bornesSaison(Rencontre::saisonDe(new \DateTimeImmutable('today')));
        $enfants = [];
        foreach ($moi->getJoueurs() as $j) {
            $mesConvocations = $convocations[$j->getId()] ?? [];
            $convoquesA = array_map(fn (Convoque $c) => $c->getConvocation()->getRencontre(), $mesConvocations);
            $enfants[] = [
                'joueur' => $j,
                'convocations' => $mesConvocations,
                'taches' => $tachesParJoueur[$j->getId()] ?? [],
                'ressentis' => $parJoueur[$j->getId()] ?? [],
                'a_noter' => $suiviRessenti->aNoter($j, $parJoueur[$j->getId()] ?? []),
                // Stages à venir de son équipe (ou de tout le club).
                'stages' => $j->getCategorie() ? $stages->entre(new \DateTimeImmutable('today'), new \DateTimeImmutable('2100-01-01'), [$j->getCategorie()]) : [],
                'entrainements' => array_values(array_filter($entrainements, fn (Entrainement $e) => $e->getCategorie() === $j->getCategorie())),
                // Les matchs déjà couverts par une convocation ne sont pas répétés.
                'matchs' => $j->getCategorie() ? array_values(array_filter($rencontres->aVenir($j->getCategorie(), 5), fn ($r) => !\in_array($r, $convoquesA, true))) : [],
                // Joueur majeur connecté avec son propre compte : ses personnes à contacter, et l'accès qu'il leur donne.
                'proches' => $acces->decideAccesParents($moi, $j) ? $proches->avecAcces($j) : null,
                // Statistiques : joueur mineur et ses parents ; joueur majeur et les contacts qu'il autorise.
                'stats' => $proches->voitStatistiques($moi, $j) ? self::statistiques($j, $bilan, $participations, $presences) : null,
                // Amendes (mêmes personnes que les statistiques) : à payer, et payées depuis le début de la saison.
                'amendes' => $proches->voitStatistiques($moi, $j) ? array_values(array_filter(
                    $sanctions->findBy(['joueur' => $j], ['date' => 'DESC']),
                    fn (Sanction $s) => $s->isAmendeAPayer() || ($s->getAmendePayeeLe() && $s->getAmendePayeeLe() >= $debutSaison),
                )) : [],
            ];
        }

        return $this->render('espace/index.html.twig', [
            // Événements des 2 prochains mois pour les équipes de ses enfants ou tout le club (hors réunions internes).
            'evenements' => $evenements->entre(new \DateTimeImmutable('today'), new \DateTimeImmutable('today +60 days'), array_values($categories), true),
            'enfants' => $enfants,
            'motifs' => Convoque::MOTIFS,
            'motifs_entrainement' => array_keys(AbsenceEntrainement::MOTIFS),
            'echelle_rpe' => Ressenti::ECHELLE,
        ]);
    }

    /**
     * Calendrier du mois pour la famille : matchs et entraînements des équipes de ses enfants, événements (hors réunions
     * internes), stages et tournois de ces équipes ou de tout le club ; les matchs où un enfant est convoqué sont signalés.
     */
    #[Route('/espace/calendrier', name: 'app_espace_calendrier', methods: ['GET'])]
    public function calendrier(Request $request, CalendrierMois $calendrier, ConvoqueRepository $convoques): Response
    {
        /** @var Utilisateur $moi */
        $moi = $this->getUser();
        if (!$moi->isFamille()) {
            return $this->redirectToRoute('app_calendrier');
        }
        $equipes = [];
        foreach ($moi->getJoueurs() as $j) {
            if ($c = $j->getCategorie()) {
                $equipes[$c->getId()] = $c;
            }
        }
        $equipes = array_values($equipes);
        $grille = $calendrier->grille($request->query->getString('mois'));
        $calendrier->planifier($equipes, $grille['fin']);
        $jours = $equipes ? $calendrier->jours($equipes, $grille['debut'], $grille['fin'], $equipes, true) : [];

        // Convocations envoyées des enfants pour les matchs de la grille : qui est convoqué.
        $convoquesParMatch = [];
        foreach ($convoques->pourMatchs($moi->getJoueurs()->toArray(), $grille['debut'], $grille['fin']) as $cq) {
            $convoquesParMatch[$cq->getConvocation()->getRencontre()->getId()][] = $cq->getJoueur()->getPrenom();
        }
        $plusieurs = $moi->getJoueurs()->count() > 1;
        foreach ($jours as &$activites) {
            foreach ($activites as &$a) {
                if ('match' === $a['type'] && isset($convoquesParMatch[$a['objet']->getId()])) {
                    $a['details'] = ['convoque' => true, 'qui' => $plusieurs ? implode(', ', $convoquesParMatch[$a['objet']->getId()]) : null];
                }
            }
            unset($a);
        }
        unset($activites);

        return $this->render('espace/calendrier.html.twig', $grille + [
            'jours' => $jours,
            'equipes' => $equipes,
            'aujourdhui' => (new \DateTimeImmutable('today'))->format('Y-m-d'),
        ]);
    }

    /** La famille inscrit son enfant à un stage, ou annule l'inscription, tant que les inscriptions sont ouvertes. */
    #[Route('/espace/stage/{id}/{joueur}', name: 'app_espace_stage', requirements: ['id' => '\d+', 'joueur' => '\d+'], methods: ['POST'])]
    public function stage(Request $request, ?Stage $stage, Joueur $joueur, EntityManagerInterface $em, AccesFamille $acces): Response
    {
        /** @var Utilisateur $moi */
        $moi = $this->getUser();
        if (!$moi->isFamille() || !$moi->suit($joueur)) {
            throw $this->createAccessDeniedException();
        }
        $retour = $this->redirect($this->generateUrl('app_espace') . '#stages-' . $joueur->getId());
        if (!$stage || !$this->isCsrfTokenValid('stage-famille' . ($stage?->getId() ?? 0) . '-' . $joueur->getId(), $request->getPayload()->getString('_token'))) {
            return $retour;
        }
        if (!$stage->isInscriptionOuverte() || !$stage->concerne(array_filter([$joueur->getCategorie()]))) {
            $this->addFlash('erreur', $stage->isObligatoire()
                ? 'Ce stage est obligatoire : en cas d\'empêchement, prévenez l\'éducateur.'
                : 'Les inscriptions à ce stage sont closes : contactez l\'encadrement.');

            return $retour;
        }
        if ('desinscrire' === $request->getPayload()->getString('action')) {
            $stage->desinscrire($joueur);
            $this->addFlash('succes', 'L\'inscription de ' . $joueur->getPrenom() . ' au stage « ' . $stage->getTitre() . ' » est annulée.');
        } elseif ($stage->inscription($joueur)) {
            return $retour;
        } elseif ($stage->isComplet()) {
            $this->addFlash('erreur', 'Le stage « ' . $stage->getTitre() . ' » est complet : contactez l\'encadrement.');

            return $retour;
        } else {
            $em->persist($stage->inscrire($joueur, true, $acces->estLeJoueur($moi, $joueur)));
            $this->addFlash('succes', $joueur->getPrenom() . ' est inscrit(e) au stage « ' . $stage->getTitre() . ' ».' . ($stage->isPayant() ? ' Le règlement se fait auprès du club.' : ''));
        }
        $em->flush();

        return $retour;
    }
    /** La famille indique que la tâche confiée au joueur est faite : la personne qui l'a confiée est prévenue. */
    #[Route('/espace/tache/{id}', name: 'app_espace_tache_faite', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function tacheFaite(Request $request, ?Tache $tache, EntityManagerInterface $em, AvisTaches $avis): Response
    {
        /** @var Utilisateur $moi */
        $moi = $this->getUser();
        if (!$tache) {
            $this->addFlash('info', 'Cette tâche n\'existe plus : l\'encadrement l\'a supprimée.');

            return $this->redirectToRoute('app_espace');
        }
        if (!$moi->isFamille() || !$tache->getJoueur() || !$moi->suit($tache->getJoueur())) {
            throw $this->createAccessDeniedException();
        }
        if ($this->isCsrfTokenValid('tache-faite' . $tache->getId(), $request->getPayload()->getString('_token')) && !$tache->isFaite()) {
            $tache->setStatut('faite');
            $em->flush();
            $j = $tache->getJoueur();
            $avis->faite($tache, $moi, 'La famille de ' . $j->getPrenom() . ' ' . $j->getNom());
            $this->addFlash('succes', 'Merci ! L\'encadrement est informé que la tâche « ' . $tache->getObjet() . ' » est faite.');
        }

        return $this->redirect($this->generateUrl('app_espace') . '#taches');
    }

    /** La famille signale une absence à un entraînement, ou la retire (finalement présent). */
    #[Route('/espace/entrainement/{id}', name: 'app_espace_entrainement', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function absenceEntrainement(Request $request, Entrainement $entrainement, JoueurRepository $joueurs, PlanningEntrainements $planning): Response
    {
        /** @var Utilisateur $moi */
        $moi = $this->getUser();
        $p = $request->getPayload();
        $joueur = $joueurs->find($p->getInt('joueur'));
        if (!$moi->isFamille() || !$joueur || !$moi->suit($joueur) || $joueur->getCategorie() !== $entrainement->getCategorie()
            || !$this->isCsrfTokenValid('entrainement' . $entrainement->getId() . '-' . $joueur->getId(), $p->getString('_token'))) {
            throw $this->createAccessDeniedException();
        }
        if (!$entrainement->isOuvert()) {
            $this->addFlash('erreur', 'Cet entraînement est passé ou annulé : rien à signaler.');
        } elseif ($p->getBoolean('retirer')) {
            if ($a = $entrainement->absenceDe($joueur)) {
                $planning->retirerAbsence($a);
            }
            $this->addFlash('succes', \sprintf('C\'est noté : %s sera présent(e) à l\'entraînement.', $joueur->getPrenom()));
        } else {
            $planning->signalerAbsence($entrainement, $joueur, $p->getString('motif'), $p->getString('commentaire'), (string) $moi->getEmail());
            $this->addFlash('succes', \sprintf('Absence de %s signalée à l\'éducateur. Merci !', $joueur->getPrenom()));
        }

        return $this->redirect($this->generateUrl('app_espace') . '#entrainements-' . $joueur->getId());
    }

    /**
     * Autoévaluation : le joueur (ou sa famille) note l'effort ressenti après un entraînement ou un match
     * des derniers jours, de 1 à 10, avec la durée. Une note déjà donnée peut être corrigée.
     */
    #[Route('/espace/ressenti/{joueur}', name: 'app_espace_ressenti', requirements: ['joueur' => '\d+'], methods: ['POST'])]
    public function ressenti(Request $request, Joueur $joueur, EntityManagerInterface $em, RessentiRepository $ressentis): Response
    {
        /** @var Utilisateur $moi */
        $moi = $this->getUser();
        $p = $request->getPayload();
        if (!$moi->isFamille() || !$moi->suit($joueur) || !$this->isCsrfTokenValid('ressenti' . $joueur->getId(), $p->getString('_token'))) {
            throw $this->createAccessDeniedException();
        }
        $retour = $this->redirect($this->generateUrl('app_espace') . '#ressenti-' . $joueur->getId());
        [$type, $id] = array_pad(explode('-', $p->getString('seance'), 2), 2, '');
        $seance = match ($type) {
            'e' => $em->getRepository(Entrainement::class)->find((int) $id),
            'r' => $em->getRepository(\App\Entity\Rencontre::class)->find((int) $id),
            default => null,
        };
        $rpe = $p->getInt('rpe');
        $limite = new \DateTimeImmutable('today -' . Ressenti::DELAI_JOURS . ' days');
        if (!$seance || $seance->getCategorie() !== $joueur->getCategorie() || $seance->getDate() < $limite || $seance->getDate() > new \DateTimeImmutable('today')) {
            $this->addFlash('erreur', 'Cette séance ne peut plus être notée.');

            return $retour;
        }
        if ($rpe < 1 || $rpe > 10) {
            $this->addFlash('erreur', 'Choisissez une note de 1 à 10.');

            return $retour;
        }
        $duree = ctype_digit($d = trim($p->getString('duree'))) && (int) $d > 0 ? (int) $d : Ressenti::dureeDe($seance);
        $existant = $ressentis->findOneBy(['joueur' => $joueur, $seance instanceof Entrainement ? 'entrainement' : 'rencontre' => $seance]);
        if ($existant) {
            $existant->modifier($rpe, $duree, $p->getString('commentaire'));
        } else {
            $em->persist((new Ressenti($joueur, $seance, $rpe, $duree, $p->getString('commentaire')))->setClub($joueur->getClub()));
        }
        $em->flush();
        $this->addFlash('succes', \sprintf('Merci ! Effort noté %d/10 (%s) pour %s.', $rpe, mb_strtolower(Ressenti::ECHELLE[$rpe]), $joueur->getPrenom()));

        return $retour;
    }

    /** Réponse de la famille à une convocation, jusqu'au jour du match. */
    #[Route('/espace/convocation/{id}', name: 'app_espace_reponse', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function repondre(Request $request, Convoque $convoque, EntityManagerInterface $em): Response
    {
        /** @var Utilisateur $moi */
        $moi = $this->getUser();
        if (!$moi->isFamille() || !$moi->suit($convoque->getJoueur()) || !$convoque->getConvocation()->getEnvoyeeLe()
            || !$this->isCsrfTokenValid('repondre' . $convoque->getId(), $request->getPayload()->getString('_token'))) {
            throw $this->createAccessDeniedException();
        }
        if (!$convoque->getConvocation()->isOuverte()) {
            $this->addFlash('erreur', 'Le match est passé : la réponse ne peut plus être modifiée.');

            return $this->redirectToRoute('app_espace');
        }
        $reponse = $request->getPayload()->getString('reponse');
        if (!\in_array($reponse, [Convoque::PRESENT, Convoque::ABSENT], true)) {
            throw $this->createAccessDeniedException();
        }
        $convoque->repondre($reponse, $request->getPayload()->getString('motif'), (string) $moi->getEmail());
        $em->flush();
        $this->addFlash('succes', \sprintf('Merci, réponse enregistrée : %s %s.', $convoque->getJoueur()->getPrenom(), Convoque::PRESENT === $reponse ? 'sera présent(e)' : 'sera absent(e)'));

        return $this->redirectToRoute('app_espace');
    }

    #[Route('/activation/{jeton}', name: 'app_activation', requirements: ['jeton' => '[0-9a-f]{48}'], methods: ['GET', 'POST'])]
    public function activation(
        string $jeton,
        Request $request,
        UtilisateurRepository $utilisateurs,
        ClubCourant $clubCourant,
        UserPasswordHasherInterface $hasher,
        EntityManagerInterface $em,
        Security $security,
    ): Response {
        $compte = $utilisateurs->findOneBy(['jetonActivation' => Utilisateur::empreinte($jeton)]);
        if (!$compte || !$compte->isJetonValide()) {
            return $this->render('espace/lien_invalide.html.twig', [], new Response('', Response::HTTP_GONE));
        }
        // Logo et couleurs du club sur la page, bien que personne ne soit encore connecté.
        $clubCourant->activer($compte->getClub());

        $form = $this->createFormBuilder()
            ->add('motDePasse', RepeatedType::class, [
                'type' => PasswordType::class,
                'first_options' => ['label' => 'Mot de passe', 'help' => '8 caractères minimum.', 'attr' => ['autocomplete' => 'new-password']],
                'second_options' => ['label' => 'Confirmation du mot de passe', 'attr' => ['autocomplete' => 'new-password']],
                'invalid_message' => 'Les deux mots de passe ne sont pas identiques.',
                'constraints' => [
                    new NotBlank(message: 'Choisissez un mot de passe.'),
                    new Length(min: 8, max: 4096, minMessage: 'Le mot de passe doit faire au moins {{ limit }} caractères.'),
                ],
            ])
            ->add('accord', CheckboxType::class, [
                'label' => 'J\'ai pris connaissance de l\'utilisation de mes données par le club (ci-dessus).',
                'constraints' => [new IsTrue(message: 'Merci de cocher cette case pour continuer.')],
            ])
            ->getForm();
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $compte->setPassword($hasher->hashPassword($compte, $form->get('motDePasse')->getData()))->effacerJeton();
            $em->flush();
            $security->login($compte, 'form_login', 'main');
            $this->addFlash('succes', 'Votre compte est prêt. À l\'avenir, connectez-vous avec votre adresse e-mail et ce mot de passe.');

            return $this->redirectToRoute('app_espace');
        }

        return $this->render('espace/activation.html.twig', ['form' => $form, 'compte' => $compte]);
    }

    /**
     * Statistiques de l'espace : chiffres par période et détail des matchs (sans note ni appréciation du coach).
     *
     * @return array{periodes: array<string, array<string, mixed>>, matchs: list<\App\Entity\Participation>, buts: array<int, array{buts: int, passes: int}>}
     */
    private static function statistiques(Joueur $j, BilanJoueur $bilan, ParticipationRepository $participations, PresenceRepository $presences): array
    {
        $matchs = $participations->parJoueur($j);

        return [
            'periodes' => self::periodes($bilan->parSaison($j, $matchs), $presences->bilanParSaison($j)),
            'matchs' => $matchs,
            'buts' => $bilan->butsParMatch($j),
        ];
    }

    /**
     * Statistiques à choisir dans l'espace : toute la carrière, puis chaque saison (la plus récente d'abord),
     * avec les matchs (feuilles de match) et la présence à l'entraînement.
     *
     * @param array<string, array<string, mixed>>                              $bilan     BilanJoueur::parSaison (avec « Total »)
     * @param array<string, array{seances: int, presences: int, taux: ?float}> $presences PresenceRepository::bilanParSaison
     *
     * @return array<string, array<string, mixed>> clé => chiffres (« carriere » en premier)
     */
    private static function periodes(array $bilan, array $presences): array
    {
        $vide = ['matchs' => 0, 'titularisations' => 0, 'minutes' => 0, 'buts' => 0, 'passes' => 0, 'jaunes' => 0, 'rouges' => 0];
        $saisons = array_unique([...array_keys(array_diff_key($bilan, ['Total' => true])), ...array_keys($presences)]);
        rsort($saisons);

        $periodes = ['carriere' => ['libelle' => 'Toute sa carrière'] + array_intersect_key($bilan['Total'] ?? [], $vide) + $vide + ['seances' => 0, 'presences' => 0]];
        foreach ($saisons as $saison) {
            $p = $presences[$saison] ?? ['seances' => 0, 'presences' => 0];
            $periodes[$saison] = ['libelle' => 'Saison ' . $saison] + array_intersect_key($bilan[$saison] ?? [], $vide) + $vide
                + ['seances' => $p['seances'], 'presences' => $p['presences']];
            $periodes['carriere']['seances'] += $p['seances'];
            $periodes['carriere']['presences'] += $p['presences'];
        }
        foreach ($periodes as &$periode) {
            $periode['taux'] = $periode['seances'] ? (int) round(100 * $periode['presences'] / $periode['seances']) : null;
        }

        return $periodes;
    }
}
