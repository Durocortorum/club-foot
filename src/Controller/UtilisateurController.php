<?php

namespace App\Controller;

use App\Entity\Categorie;
use App\Entity\ResponsableSecteur;
use App\Entity\Utilisateur;
use App\Form\ChangementMotDePasseType;
use App\Form\UtilisateurType;
use App\Repository\DemandeAccesMedicalRepository;
use App\Repository\UtilisateurRepository;
use App\Service\InvitationCompte;
use App\Form\MaFicheType;
use App\Security\ActiviteVoter;
use App\Security\JoueurVoter;
use App\Service\FicheDuCompte;
use App\Service\MesEquipes;
use App\Service\Photos;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormError;
use Symfony\Component\Validator\Constraints\Email;
use Symfony\Component\Validator\Constraints\NotBlank;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Gestion des comptes (administrateurs) et changement de son propre mot de passe.
 */
class UtilisateurController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly UserPasswordHasherInterface $hasher,
        private readonly JoueurVoter $joueurVoter,
        private readonly ActiviteVoter $activiteVoter,
    ) {
    }

    #[Route('/utilisateurs', name: 'app_utilisateur_index', methods: ['GET'])]
    #[IsGranted('ROLE_ADMIN')]
    public function index(UtilisateurRepository $utilisateurs): Response
    {
        return $this->render('utilisateur/index.html.twig', [
            // Les comptes famille se gèrent depuis les fiches joueurs (« Accès famille »).
            'utilisateurs' => $utilisateurs->findBy(['famille' => false], ['nom' => 'ASC', 'prenom' => 'ASC']),
            'familles' => $utilisateurs->count(['famille' => true]),
        ]);
    }

    /**
     * Tableau des droits d'accès : une ligne par compte, une case à cliquer par section.
     * Administrateur : toutes les colonnes. Médecin du club : seulement les droits médicaux.
     */
    #[Route('/utilisateurs/droits', name: 'app_utilisateur_droits', methods: ['GET'])]
    public function droits(UtilisateurRepository $utilisateurs, DemandeAccesMedicalRepository $demandes): Response
    {
        $modifiables = $this->colonnesModifiables();
        if (!$modifiables) {
            throw $this->createAccessDeniedException();
        }

        return $this->render('utilisateur/droits.html.twig', [
            'utilisateurs' => $utilisateurs->findBy(['famille' => false], ['nom' => 'ASC', 'prenom' => 'ASC']),
            'colonnes' => Utilisateur::colonnesDroits(),
            'modifiables' => $modifiables,
            'medicales' => Utilisateur::SECTIONS_MEDICALES,
            'durees' => Utilisateur::DUREES_MEDICALES,
            // Demandes d'accès médical à traiter (médecin du club ou administrateur).
            'demandes' => array_intersect(Utilisateur::SECTIONS_MEDICALES, $modifiables) ? $demandes->enAttente() : [],
        ]);
    }

    /**
     * Le médecin du club (ou l'administrateur) crée le compte d'une personne qui n'aura que du médical
     * (kiné, infirmière...) : droits Infirmerie / Visite médicale avec échéance, et invitation par e-mail
     * pour choisir son mot de passe. Le compte ne voit que l'Infirmerie (compte médical seul).
     */
    #[Route('/utilisateurs/droits/nouvelle-personne', name: 'app_droits_nouvelle_personne', methods: ['GET', 'POST'])]
    public function nouvellePersonneMedicale(Request $request, UtilisateurRepository $utilisateurs, InvitationCompte $invitation): Response
    {
        if (!array_intersect(Utilisateur::SECTIONS_MEDICALES, $this->colonnesModifiables())) {
            throw $this->createAccessDeniedException();
        }
        $niveaux = ['Aucun accès' => 0, 'Voir (consultation seule)' => 1, 'Modifier (consultation et saisie)' => 2];
        $form = $this->createFormBuilder(['infirmerie' => 1, 'visite_medicale' => 0, 'duree' => '30'])
            ->add('prenom', TextType::class, ['label' => 'Prénom', 'constraints' => [new NotBlank(message: 'Indiquez le prénom.')]])
            ->add('nom', TextType::class, ['constraints' => [new NotBlank(message: 'Indiquez le nom.')]])
            ->add('email', EmailType::class, [
                'label' => 'Adresse e-mail',
                'help' => 'Elle sert à se connecter et à recevoir les codes d\'accès aux données médicales : une adresse réelle.',
                'constraints' => [new NotBlank(message: 'Indiquez l\'adresse e-mail.'), new Email(message: 'Adresse e-mail invalide.')],
            ])
            ->add('infirmerie', ChoiceType::class, ['label' => '🔒 Infirmerie (blessures, renseignements médicaux, contacts)', 'choices' => $niveaux, 'expanded' => true])
            ->add('visite_medicale', ChoiceType::class, ['label' => '🔒 Visite médicale (poids, taille, IMC)', 'choices' => $niveaux, 'expanded' => true])
            ->add('duree', ChoiceType::class, ['label' => 'Pendant', 'choices' => array_flip(Utilisateur::DUREES_MEDICALES)])
            ->getForm();
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $donnees = $form->getData();
            $email = mb_strtolower(trim($donnees['email']));
            if (0 === $donnees['infirmerie'] + $donnees['visite_medicale']) {
                $form->addError(new FormError('Choisissez au moins un accès : Infirmerie ou Visite médicale.'));
            } elseif ($utilisateurs->tousClubs(['email' => $email])) {
                $form->get('email')->addError(new FormError('Cette adresse a déjà un compte. S\'il est de votre club, donnez-lui l\'accès dans le tableau des droits médicaux.'));
            } else {
                $echeance = Utilisateur::echeanceMedicale($donnees['duree']);
                $compte = (new Utilisateur())->setEmail($email)->setNom(trim($donnees['nom']))->setPrenom(trim($donnees['prenom']));
                foreach (Utilisateur::SECTIONS_MEDICALES as $section) {
                    if ($donnees[$section] > 0) {
                        $compte->setNiveau($section, $donnees[$section], $echeance);
                    }
                }
                /** @var Utilisateur $moi */
                $moi = $this->getUser();
                $club = (string) $moi->getClub()?->getNom();
                $resultat = $invitation->inviter($compte, $moi->getClub(), 'votre accès aux données médicales',
                    \sprintf('vous ouvre un accès aux données médicales du club (%s), jusqu\'au %s.',
                        implode(', ', array_map(fn ($s) => Utilisateur::SECTIONS[$s]['libelle'], array_filter(Utilisateur::SECTIONS_MEDICALES, fn ($s) => $donnees[$s] > 0))),
                        $echeance->format('d/m/Y')));
                $this->em->persist($compte);
                $this->em->flush();
                $this->addFlash('succes', $resultat['envoye']
                    ? \sprintf('Compte créé pour %s (accès jusqu\'au %s) : un e-mail lui permet de choisir son mot de passe.', $compte, $echeance->format('d/m/Y'))
                    : \sprintf('Compte créé pour %s, mais l\'e-mail n\'est pas parti. Transmettez-lui ce lien (%d jours) : %s', $compte, InvitationCompte::DUREE_JOURS, $resultat['lien']));

                return $this->redirectToRoute('app_utilisateur_droits');
            }
        }

        return $this->render('commun/formulaire.html.twig', [
            'form' => $form,
            'titre' => 'Nouvelle personne – accès médical',
            'aide' => 'Pour un kiné, une infirmière... qui n\'a besoin que du médical : son compte ne verra que l\'Infirmerie et les dossiers médicaux, pour tout le club, jusqu\'à l\'échéance choisie. Elle recevra un e-mail pour choisir son mot de passe.',
            'retour' => $this->generateUrl('app_utilisateur_droits'),
        ]);
    }

    /**
     * Donne ou retire un droit depuis le tableau. Répond en JSON au clic (sans recharger la page),
     * ou par une redirection si le navigateur envoie le formulaire classique.
     */
    #[Route('/utilisateurs/{id}/droit', name: 'app_utilisateur_droit', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function basculerDroit(Request $request, Utilisateur $utilisateur): Response
    {
        $donnees = $request->getPayload();
        // « droit » : un rôle simple (ROLE_...) ou une section à deux niveaux (infirmerie, scolarite...).
        // « niveau » : 0 aucun, 1 oui (ou « voir » pour une section), 2 « modifier » (sections seulement).
        // « duree » : pour un droit médical, durée avant échéance (voir Utilisateur::DUREES_MEDICALES).
        $droit = $donnees->getString('droit');
        $niveau = $donnees->getInt('niveau');
        $colonnes = array_column(Utilisateur::colonnesDroits(), null, 'cle');
        $colonne = $colonnes[$droit] ?? null;
        $medical = \in_array($droit, Utilisateur::SECTIONS_MEDICALES, true);
        $echeance = $medical && $niveau > 0 ? Utilisateur::echeanceMedicale($donnees->getString('duree', '30')) : null;
        $json = \in_array('application/json', $request->getAcceptableContentTypes(), true);

        $erreur = match (true) {
            !$this->isCsrfTokenValid('droits-utilisateurs', $donnees->getString('_token')) => 'La page a expiré : rechargez-la.',
            $utilisateur->isFamille() || null === $colonne || $niveau < 0 || $niveau > (null === $colonne['niveaux'] ? 1 : 2) => 'Droit inconnu.',
            !\in_array($droit, $this->colonnesModifiables(), true) => $medical
                ? 'Seuls un médecin du club ou l\'administrateur donnent les droits médicaux.'
                : 'Ce droit ne peut être changé que par l\'administrateur.',
            $medical && $niveau > 0 && null === $echeance => 'Durée inconnue.',
            0 === $niveau && 'ROLE_ADMIN' === $droit && $utilisateur === $this->getUser() => 'Vous ne pouvez pas retirer votre propre accès à l\'administration.',
            default => null,
        };

        if (null === $erreur) {
            if (null === $colonne['niveaux']) {
                $utilisateur->basculerRole($droit, 1 === $niveau);
                $etat = $niveau ? 'accordé' : 'retiré';
            } else {
                $utilisateur->setNiveau($droit, $niveau, $echeance);
                $etat = ['retiré', 'en consultation (voir)', 'en modification'][$niveau];
            }
            $this->em->flush();
            $message = sprintf('%s : « %s » %s%s.', $utilisateur, $colonne['libelle'], $etat, $echeance ? ' jusqu\'au ' . $echeance->format('d/m/Y') : '');
        }

        if ($json) {
            return $this->json(
                null === $erreur
                    ? ['roles' => $utilisateur->getRolesAttribues(), 'echeances' => $this->echeances($utilisateur), 'message' => $message]
                    : ['erreur' => $erreur],
                null === $erreur ? 200 : 422,
            );
        }
        $this->addFlash(null === $erreur ? 'succes' : 'erreur', $erreur ?? $message);

        return $this->redirectToRoute('app_utilisateur_droits', [], 303);
    }

    /**
     * Colonnes du tableau que le compte connecté peut changer : tout pour l'administrateur,
     * les droits médicaux pour un médecin du club.
     *
     * @return list<string>
     */
    private function colonnesModifiables(): array
    {
        if ($this->isGranted('ROLE_ADMIN')) {
            return array_column(Utilisateur::colonnesDroits(), 'cle');
        }

        return $this->isGranted('ROLE_MEDECIN') ? Utilisateur::SECTIONS_MEDICALES : [];
    }

    /** @return array<string, ?string> section médicale => échéance (jj/mm/aaaa) */
    private function echeances(Utilisateur $u): array
    {
        $echeances = [];
        foreach (Utilisateur::SECTIONS_MEDICALES as $section) {
            $echeances[$section] = $u->getEcheance($section)?->format('d/m/Y');
        }

        return $echeances;
    }

    #[Route('/utilisateurs/nouveau',name: 'app_utilisateur_nouveau', methods: ['GET', 'POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function nouveau(Request $request): Response
    {
        return $this->formulaire($request, new Utilisateur(), 'Nouveau compte');
    }

    #[Route('/utilisateurs/{id}/modifier', name: 'app_utilisateur_modifier', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function modifier(Request $request, Utilisateur $utilisateur): Response
    {
        if ($utilisateur->isFamille()) {
            throw $this->createNotFoundException();
        }

        return $this->formulaire($request, $utilisateur, 'Compte de ' . $utilisateur);
    }

    /**
     * Suspend un compte : plus de connexion, d'e-mails ni d'alertes, plus de responsabilité sur les équipes ;
     * tout est gardé. Date de fin facultative (réactivé tout seul le lendemain), motif facultatif.
     */
    #[Route('/utilisateurs/{id}/suspendre', name: 'app_utilisateur_suspendre', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function suspendre(Request $request, Utilisateur $utilisateur): Response
    {
        $p = $request->getPayload();
        if ($utilisateur === $this->getUser()) {
            $this->addFlash('erreur', 'Vous ne pouvez pas suspendre votre propre compte.');
        } elseif ($this->isCsrfTokenValid('suspendre-utilisateur' . $utilisateur->getId(), $p->getString('_token'))) {
            $fin = \DateTimeImmutable::createFromFormat('!Y-m-d', $p->getString('jusqu_au')) ?: null;
            if ($fin && $fin < new \DateTimeImmutable('today')) {
                $this->addFlash('erreur', 'La date de fin est déjà passée : choisissez aujourd\'hui ou plus tard, ou laissez vide.');
            } else {
                $utilisateur->suspendre($fin, $p->getString('motif') ?: null);
                $this->em->flush();
                $this->addFlash('succes', \sprintf('Compte de %s suspendu%s : il ne peut plus se connecter et ne reçoit plus les e-mails du site.',
                    $utilisateur, $fin ? ' jusqu\'au ' . $fin->format('d/m/Y') . ' inclus' : ' jusqu\'à sa réactivation'));
            }
        }

        return $this->redirectToRoute('app_utilisateur_modifier', ['id' => $utilisateur->getId(), '_fragment' => 'suspension']);
    }

    #[Route('/utilisateurs/{id}/reactiver', name: 'app_utilisateur_reactiver', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function reactiver(Request $request, Utilisateur $utilisateur): Response
    {
        if ($this->isCsrfTokenValid('reactiver-utilisateur' . $utilisateur->getId(), $request->getPayload()->getString('_token'))) {
            $utilisateur->reactiver();
            $this->em->flush();
            $this->addFlash('succes', \sprintf('Compte de %s réactivé : tout est comme avant la suspension.', $utilisateur));
        }

        return $this->redirectToRoute('app_utilisateur_modifier', ['id' => $utilisateur->getId(), '_fragment' => 'suspension']);
    }

    #[Route('/utilisateurs/{id}/supprimer', name: 'app_utilisateur_supprimer', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function supprimer(Request $request, Utilisateur $utilisateur): Response
    {
        if ($utilisateur === $this->getUser()) {
            $this->addFlash('erreur', 'Vous ne pouvez pas supprimer votre propre compte.');
        } elseif ($this->isCsrfTokenValid('supprimer-utilisateur' . $utilisateur->getId(), $request->getPayload()->getString('_token'))) {
            $this->em->remove($utilisateur);
            $this->em->flush();
            $this->addFlash('succes', 'Compte supprimé.');
        }

        return $this->redirectToRoute('app_utilisateur_index');
    }

    #[Route('/mon-compte', name: 'app_mon_compte', methods: ['GET', 'POST'])]
    public function monCompte(Request $request, FicheDuCompte $fiches, MesEquipes $mesEquipes): Response
    {
        /** @var Utilisateur $moi */
        $moi = $this->getUser();
        // « Mes e-mails » : cases cochées = e-mails reçus ; les autres sont coupés.
        if ($request->isMethod('POST') && $request->request->has('emails_choix')) {
            if (!$this->isCsrfTokenValid('mes-emails', $request->getPayload()->getString('_token'))) {
                throw $this->createAccessDeniedException();
            }
            $recus = $request->getPayload()->all('emails');
            $moi->setEmailsCoupes(array_values(array_diff(array_keys(Utilisateur::EMAILS_AU_CHOIX), $recus)));
            $this->em->flush();
            $this->addFlash('succes', 'Vos choix d\'e-mails sont enregistrés.');

            return $this->redirect($this->generateUrl('app_mon_compte') . '#mes-emails');
        }
        $form = $this->createForm(ChangementMotDePasseType::class);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $moi->setPassword($this->hasher->hashPassword($moi, $form->get('nouveau')->getData()));
            $this->em->flush();
            $this->addFlash('succes', 'Mot de passe modifié.');

            return $this->redirectToRoute('app_mon_compte');
        }

        // Encadrement : secteurs dont il est responsable (page Catégories), sa fiche Encadrement, ses équipes.
        $secteurs = [];
        if (!$moi->isFamille()) {
            foreach ($this->em->getRepository(ResponsableSecteur::class)->findBy(['utilisateur' => $moi]) as $r) {
                $secteurs[] = Categorie::SECTEURS[$r->getSecteur()]['libelle'] ?? $r->getSecteur();
            }
        }
        $encadrement = !$moi->isFamille() && \in_array('ROLE_ENCADREMENT', $moi->getRoles(), true);

        return $this->render('utilisateur/mon_compte.html.twig', [
            'form' => $form,
            'moi' => $moi,
            'secteurs_responsable' => $secteurs,
            'encadrement' => $encadrement,
            'fiches' => $encadrement ? $fiches->fiches($moi) : [],
            'mes_equipes' => $encadrement ? $mesEquipes->de($moi) : [],
            // Pages d'une équipe ouvertes à ce compte : présences et planning (droit du secteur), matchs.
            'secteurs_vus' => $this->joueurVoter->secteursModifiables(),
            'secteurs_matchs' => $this->activiteVoter->secteursMatchs(),
        ]);
    }

    /**
     * Mise à jour par chacun de sa propre fiche Encadrement : photo et coordonnées seulement
     * (équipes, fonction, diplôme… restent à l'administrateur).
     */
    #[Route('/mon-compte/fiche/{type}', name: 'app_ma_fiche', requirements: ['type' => 'educateurs|dirigeants|administratifs|benevoles|arbitres'], methods: ['GET', 'POST'])]
    public function maFiche(Request $request, string $type, FicheDuCompte $fiches, Photos $photos): Response
    {
        /** @var Utilisateur $moi */
        $moi = $this->getUser();
        $fiche = $moi->isFamille() ? null : $fiches->fiche($moi, $type);
        if (!$fiche) {
            throw $this->createAccessDeniedException();
        }
        $form = $this->createForm(MaFicheType::class, $fiche, ['data_class' => $fiche::class]);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            if ($fichier = $form->get('photo')->getData()) {
                $fiche->setPhoto($photos->enregistrer($fichier, rtrim($type, 's'), $fiche->getPhoto()));
            }
            $this->em->flush();
            $this->addFlash('succes', 'Vos coordonnées sont enregistrées.');

            return $this->redirectToRoute('app_mon_compte');
        }

        return $this->render('utilisateur/ma_fiche.html.twig', ['form' => $form, 'fiche' => $fiche]);
    }

    private function formulaire(Request $request, Utilisateur $utilisateur, string $titre): Response
    {
        $soiMeme = $utilisateur === $this->getUser();
        // Droits médicaux : absents du formulaire, ils sont conservés tels quels (tableau des droits d'accès).
        $medicaux = array_values(array_intersect($utilisateur->getRolesAttribues(), Utilisateur::rolesMedicaux()));
        $form = $this->createForm(UtilisateurType::class, $utilisateur, [
            'mot_de_passe_obligatoire' => null === $utilisateur->getId(),
            'soi_meme' => $soiMeme,
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            if ($soiMeme && !$utilisateur->isAdmin()) {
                // La case est désactivée pour soi-même et n'est donc pas envoyée : on la rétablit.
                $utilisateur->setRolesAttribues([...$utilisateur->getRolesAttribues(), 'ROLE_ADMIN']);
            }
            $utilisateur->setRolesAttribues(array_values(array_unique([...$utilisateur->getRolesAttribues(), ...$medicaux])));
            if ($motDePasse = $form->get('motDePasse')->getData()) {
                $utilisateur->setPassword($this->hasher->hashPassword($utilisateur, $motDePasse));
            }
            $this->em->persist($utilisateur);
            $this->em->flush();
            $this->addFlash('succes', $motDePasse ? 'Compte enregistré, avec le nouveau mot de passe.' : 'Compte enregistré.');

            return $this->redirectToRoute('app_utilisateur_index');
        }

        $vue = ['form' => $form, 'titre' => $titre, 'utilisateur' => $utilisateur];
        if ($utilisateur->getId() && !$soiMeme) {
            $vue['suppression'] = [
                'action' => $this->generateUrl('app_utilisateur_supprimer', ['id' => $utilisateur->getId()]),
                'jeton' => 'supprimer-utilisateur' . $utilisateur->getId(),
                'question' => 'Supprimer le compte de ' . $utilisateur . ' ? Cette personne ne pourra plus se connecter.',
            ];
        }

        return $this->render('utilisateur/formulaire.html.twig', $vue);
    }
}
