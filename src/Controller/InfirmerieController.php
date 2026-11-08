<?php

namespace App\Controller;

use App\Entity\Blessure;
use App\Entity\ContactMedical;
use App\Entity\Joueur;
use App\Form\BlessureType;
use App\Form\ContactMedicalType;
use App\Repository\BlessureRepository;
use App\Repository\CategorieRepository;
use App\Repository\ContactMedicalRepository;
use App\Repository\JoueurRepository;
use App\Security\JoueurVoter;
use App\Service\Anatomie;
use App\Service\InvitationCompte;
use App\Entity\Utilisateur;
use App\Repository\UtilisateurRepository;
use App\Service\AccesFamille;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\FormError;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email as MimeEmail;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Validator\Constraints\Email;
use Symfony\Component\Validator\Constraints\NotBlank;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Suivi des blessures et carnet d'adresses médical. Réservé à l'infirmerie.
 */
#[Route('/infirmerie')]
#[IsGranted('ROLE_INFIRMERIE_LECTURE')]
class InfirmerieController extends AbstractController
{
    public function __construct(
        private readonly Anatomie $anatomie,
        private readonly JoueurVoter $voter,
    ) {
    }

    #[Route('', name: 'app_infirmerie_index', methods: ['GET'])]
    public function index(Request $request, BlessureRepository $blessures, CategorieRepository $categories): Response
    {
        $filtres = [
            'statut' => 'toutes' === $request->query->getString('statut') ? 'toutes' : 'en_cours',
            'q' => trim($request->query->getString('q')) ?: null,
            'categorie' => $request->query->getInt('categorie') ?: null,
        ];
        $page = max(1, $request->query->getInt('page', 1));
        // Éducateur : ses secteurs seulement ; médecin, compte médical seul, administrateur : tout le club.
        $secteurs = $this->voter->secteursMedicaux();
        $resultats = $blessures->rechercher($filtres['statut'], $filtres['q'], $filtres['categorie'], $page, $secteurs);

        return $this->render('infirmerie/index.html.twig', [
            'blessures' => $resultats,
            'total' => \count($resultats),
            'pages' => max(1, (int) ceil(\count($resultats) / BlessureRepository::PAR_PAGE)),
            'page' => $page,
            'filtres' => $filtres,
            'categories' => $categories->findBy(null === $secteurs ? [] : ['secteur' => $secteurs], ['ordre' => 'ASC']),
            'par_type' => $blessures->compterParType(10, $secteurs),
        ]);
    }

    #[IsGranted('ROLE_INFIRMERIE')]
    #[Route('/blessures/nouvelle', name: 'app_blessure_nouvelle', methods: ['GET', 'POST'])]
    public function nouvelleBlessure(Request $request, EntityManagerInterface $em, JoueurRepository $joueurs): Response
    {
        $blessure = (new Blessure())->setDate(new \DateTimeImmutable('today'));
        $joueur = $request->query->getInt('joueur') ? $joueurs->find($request->query->getInt('joueur')) : null;
        if ($joueur) {
            $this->verifierPerimetre($joueur);
            $blessure->setJoueur($joueur)->setEquipe($joueur->getCategorie()?->getNom());
        }

        return $this->formulaireBlessure($request, $em, $blessure, 'Nouvelle blessure', null !== $joueur);
    }

    #[IsGranted('ROLE_INFIRMERIE')]
    #[Route('/blessures/{id}/modifier', name: 'app_blessure_modifier', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function modifierBlessure(Request $request, EntityManagerInterface $em, Blessure $blessure): Response
    {
        $this->verifierPerimetre($blessure->getJoueur());

        return $this->formulaireBlessure($request, $em, $blessure, 'Blessure de ' . $blessure->getJoueur()->getNomComplet(), true);
    }

    #[IsGranted('ROLE_INFIRMERIE')]
    #[Route('/blessures/{id}/supprimer', name: 'app_blessure_supprimer', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function supprimerBlessure(Request $request, EntityManagerInterface $em, Blessure $blessure): Response
    {
        $joueur = $blessure->getJoueur();
        $this->verifierPerimetre($joueur);
        if ($this->isCsrfTokenValid('supprimer-blessure' . $blessure->getId(), $request->getPayload()->getString('_token'))) {
            $em->remove($blessure);
            $em->flush();
            $this->addFlash('succes', 'Blessure supprimée.');
        }

        return $this->redirectToRoute('app_dossier_medical', ['id' => $joueur->getId(), '_fragment' => 'infirmerie']);
    }

    #[Route('/contacts', name: 'app_contact_index', methods: ['GET'])]
    public function contacts(ContactMedicalRepository $contacts): Response
    {
        return $this->render('infirmerie/contacts.html.twig', [
            'contacts' => $contacts->findBy([], ['profession' => 'ASC', 'nom' => 'ASC']),
        ]);
    }

    #[IsGranted('ROLE_INFIRMERIE')]
    #[Route('/contacts/nouveau', name: 'app_contact_nouveau', methods: ['GET', 'POST'])]
    #[Route('/contacts/{id}/modifier', name: 'app_contact_modifier', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function formulaireContact(Request $request, EntityManagerInterface $em, ?ContactMedical $contact = null): Response
    {
        $contact ??= new ContactMedical();
        $form = $this->createForm(ContactMedicalType::class, $contact);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $em->persist($contact);
            $em->flush();
            $this->addFlash('succes', 'Contact enregistré.');

            return $this->redirectToRoute('app_contact_index');
        }

        return $this->render('commun/formulaire.html.twig', [
            'form' => $form,
            'titre' => $contact->getId() ? 'Modifier le contact' : 'Nouveau contact médical',
            'retour' => $this->generateUrl('app_contact_index'),
        ]);
    }

    /**
     * Crée le compte d'un médecin du club à partir de son contact (ou donne le rôle Médecin à un compte existant).
     * Le médecin reçoit par e-mail un lien (14 jours) pour choisir lui-même son mot de passe.
     */
    #[IsGranted('ROLE_ADMIN')]
    #[Route('/contacts/{id}/compte-medecin', name: 'app_contact_compte_medecin', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function compteMedecin(
        Request $request,
        ContactMedical $contact,
        EntityManagerInterface $em,
        UtilisateurRepository $utilisateurs,
        InvitationCompte $invitation,
    ): Response {
        $form = $this->createFormBuilder()
            ->add('email', EmailType::class, [
                'label' => 'Adresse e-mail du médecin',
                'help' => 'Elle sert à se connecter et à recevoir les codes d\'accès aux données médicales : une adresse personnelle et réelle.',
                'constraints' => [new NotBlank(message: 'Indiquez son adresse e-mail.'), new Email(message: 'Adresse e-mail invalide.')],
            ])
            ->getForm();
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $email = mb_strtolower(trim($form->get('email')->getData()));
            $compte = $utilisateurs->tousClubs(['email' => $email])[0] ?? null;
            /** @var Utilisateur $moi */
            $moi = $this->getUser();

            if ($compte && ($compte->isFamille() || $compte->getClub() !== $moi->getClub())) {
                $form->get('email')->addError(new FormError('Cette adresse est déjà utilisée par un compte famille ou un autre club.'));
            } elseif ($compte) {
                $compte->basculerRole('ROLE_MEDECIN', true);
                $em->flush();
                $this->addFlash('succes', \sprintf('%s a déjà un compte : il est maintenant médecin du club.', $compte));

                return $this->redirectToRoute('app_contact_index');
            } else {
                $compte = (new Utilisateur())
                    ->setEmail($email)
                    ->setNom((string) $contact->getNom())
                    ->setPrenom($contact->getPrenom() ?: 'Docteur')
                    ->setRolesAttribues(['ROLE_MEDECIN']);
                $resultat = $invitation->inviter($compte, $moi->getClub(), 'votre compte de médecin du club',
                    'vous ouvre un compte de médecin du club sur son application : infirmerie, blessures, visites médicales, et les droits médicaux que vous donnez aux autres membres.');
                $em->persist($compte);
                $em->flush();
                [$lien, $envoye] = [$resultat['lien'], $resultat['envoye']];
                $this->addFlash('succes', $envoye
                    ? \sprintf('Compte médecin créé pour %s : un e-mail lui permet de choisir son mot de passe.', $email)
                    : \sprintf('Compte médecin créé pour %s, mais l\'e-mail n\'est pas parti. Transmettez-lui ce lien (14 jours) : %s', $email, $lien));

                return $this->redirectToRoute('app_contact_index');
            }
        }

        return $this->render('commun/formulaire.html.twig', [
            'form' => $form,
            'titre' => 'Compte médecin – ' . trim($contact->getPrenom() . ' ' . $contact->getNom()),
            'aide' => 'Le médecin aura tout le médical (Infirmerie, Visite médicale) et pourra donner ces droits aux autres membres, pour une durée limitée. Il recevra un e-mail pour choisir son mot de passe.',
            'retour' => $this->generateUrl('app_contact_index'),
        ]);
    }
    #[IsGranted('ROLE_INFIRMERIE')]
    #[Route('/contacts/{id}/supprimer', name: 'app_contact_supprimer', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function supprimerContact(Request $request, EntityManagerInterface $em, ContactMedical $contact): Response
    {
        if ($this->isCsrfTokenValid('supprimer-contact' . $contact->getId(), $request->getPayload()->getString('_token'))) {
            $em->remove($contact);
            $em->flush();
            $this->addFlash('succes', 'Contact supprimé.');
        }

        return $this->redirectToRoute('app_contact_index');
    }

    private function formulaireBlessure(Request $request, EntityManagerInterface $em, Blessure $blessure, string $titre, bool $joueurFixe): Response
    {
        $form = $this->createForm(BlessureType::class, $blessure, ['joueur_fixe' => $joueurFixe, 'secteurs' => $this->voter->secteursMedicaux()]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $em->persist($blessure);
            $em->flush();
            $this->addFlash('succes', 'Blessure enregistrée.');

            return $this->redirectToRoute('app_dossier_medical', ['id' => $blessure->getJoueur()->getId(), '_fragment' => 'infirmerie']);
        }

        $vue = [
            'form' => $form,
            'titre' => $titre,
            'planches' => $this->anatomie->planches(),
            'retour' => $blessure->getJoueur()
                ? $this->generateUrl('app_dossier_medical', ['id' => $blessure->getJoueur()->getId()])
                : $this->generateUrl('app_infirmerie_index'),
        ];
        if ($blessure->getId()) {
            $vue['suppression'] = [
                'action' => $this->generateUrl('app_blessure_supprimer', ['id' => $blessure->getId()]),
                'jeton' => 'supprimer-blessure' . $blessure->getId(),
                'question' => 'Supprimer cette blessure ?',
            ];
        }

        return $this->render('infirmerie/blessure.html.twig', $vue);
    }

    /** Joueur hors du périmètre médical (autre secteur pour un éducateur) : refusé. */
    private function verifierPerimetre(?Joueur $joueur): void
    {
        if ($joueur && !$this->voter->dansPerimetreMedical($joueur)) {
            throw $this->createAccessDeniedException();
        }
    }
}
