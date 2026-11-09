<?php

namespace App\Controller;

use App\Entity\AbsenceEntrainement;
use App\Entity\Convoque;
use App\Entity\Entrainement;
use App\Entity\Joueur;
use App\Entity\Utilisateur;
use App\Service\AccesFamille;
use App\Service\ProchesJoueur;
use App\Repository\ConvoqueRepository;
use App\Repository\EvenementRepository;
use App\Repository\ParticipationRepository;
use App\Repository\PresenceRepository;
use App\Service\BilanJoueur;
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

        $enfants = [];
        foreach ($moi->getJoueurs() as $j) {
            $mesConvocations = $convocations[$j->getId()] ?? [];
            $convoquesA = array_map(fn (Convoque $c) => $c->getConvocation()->getRencontre(), $mesConvocations);
            $enfants[] = [
                'joueur' => $j,
                'convocations' => $mesConvocations,
                'entrainements' => array_values(array_filter($entrainements, fn (Entrainement $e) => $e->getCategorie() === $j->getCategorie())),
                // Les matchs déjà couverts par une convocation ne sont pas répétés.
                'matchs' => $j->getCategorie() ? array_values(array_filter($rencontres->aVenir($j->getCategorie(), 5), fn ($r) => !\in_array($r, $convoquesA, true))) : [],
                // Joueur majeur connecté avec son propre compte : ses personnes à contacter, et l'accès qu'il leur donne.
                'proches' => $acces->decideAccesParents($moi, $j) ? $proches->avecAcces($j) : null,
                // Statistiques : joueur mineur et ses parents ; joueur majeur et les contacts qu'il autorise.
                'stats' => $proches->voitStatistiques($moi, $j) ? self::statistiques($j, $bilan, $participations, $presences) : null,
            ];
        }

        return $this->render('espace/index.html.twig', [
            // Événements des 2 prochains mois pour les équipes de ses enfants ou tout le club (hors réunions internes).
            'evenements' => $evenements->entre(new \DateTimeImmutable('today'), new \DateTimeImmutable('today +60 days'), array_values($categories), true),
            'enfants' => $enfants,
            'motifs' => Convoque::MOTIFS,
            'motifs_entrainement' => array_keys(AbsenceEntrainement::MOTIFS),
        ]);
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
