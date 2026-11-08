<?php

namespace App\Controller;

use App\Entity\Utilisateur;
use App\Repository\UtilisateurRepository;
use App\Service\AccesFamille;
use App\Service\CourrielClub;
use App\Service\ClubCourant;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\RepeatedType;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;

/**
 * Mot de passe oublié : on saisit son adresse, on reçoit un lien valable une heure,
 * on choisit un nouveau mot de passe. La réponse est la même que l'adresse existe ou non.
 */
class MotDePasseController extends AbstractController
{
    #[Route('/mot-de-passe-oublie', name: 'app_mot_de_passe_oublie', methods: ['GET', 'POST'])]
    public function demande(
        Request $request,
        UtilisateurRepository $utilisateurs,
        AccesFamille $acces,
        MailerInterface $mailer,
        EntityManagerInterface $em,
        LoggerInterface $logger,
        RateLimiterFactory $motDePasseOublieLimiter,
        CourrielClub $courriel,
    ): Response {
        // Pas de renvoi si l'on est déjà connecté : on peut avoir oublié le mot de passe d'un autre compte
        // (un autre club, par exemple). Le nouveau lien connecte alors au compte concerné.
        $email = mb_strtolower(trim($request->getPayload()->getString('email', $request->query->getString('email'))));

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('mot-de-passe-oublie', $request->getPayload()->getString('_token'))) {
                $this->addFlash('erreur', 'La page a expiré : réessayez.');

                return $this->redirectToRoute('app_mot_de_passe_oublie');
            }
            if (!$motDePasseOublieLimiter->create($request->getClientIp())->consume()->isAccepted()) {
                $this->addFlash('erreur', 'Trop de demandes : réessayez dans un quart d\'heure.');

                return $this->redirectToRoute('app_mot_de_passe_oublie');
            }

            $compte = $utilisateurs->tousClubs(['email' => $email])[0] ?? null;
            if ($compte && $acces->emailActif() && !str_ends_with($email, '.invalid')) {
                $lien = $this->generateUrl('app_mot_de_passe_nouveau', ['jeton' => $compte->jetonReinitialisation()], UrlGeneratorInterface::ABSOLUTE_URL);
                $em->flush();
                $club = $compte->getClub();
                try {
                    $mailer->send($courriel->message($club, (string) $compte->getEmail(), $club->getNom() . ' : nouveau mot de passe', [
                        'titre' => 'Choisir un nouveau mot de passe',
                        'paragraphes' => [\sprintf('Vous avez demandé à changer le mot de passe de votre compte %s.', $compte->getEmail())],
                        'bouton' => ['libelle' => 'Choisir mon mot de passe', 'url' => $lien],
                        'note' => "Ce lien est valable une heure.\nVous n'avez rien demandé ? Ignorez ce message : votre mot de passe actuel reste valable.",
                    ]));
                } catch (TransportExceptionInterface $e) {
                    $logger->error('Lien « mot de passe oublié » non envoyé à {email} : {erreur}', ['email' => $compte->getEmail(), 'erreur' => $e->getMessage()]);
                }
            }

            return $this->render('security/mot_de_passe_envoye.html.twig', ['email' => $email]);
        }

        return $this->render('security/mot_de_passe_oublie.html.twig', ['email' => $email]);
    }

    #[Route('/mot-de-passe/{jeton}', name: 'app_mot_de_passe_nouveau', requirements: ['jeton' => '[0-9a-f]{48}'], methods: ['GET', 'POST'])]
    public function nouveau(
        string $jeton,
        Request $request,
        UtilisateurRepository $utilisateurs,
        ClubCourant $clubCourant,
        UserPasswordHasherInterface $hasher,
        EntityManagerInterface $em,
        Security $security,
    ): Response {
        /** @var Utilisateur|null $compte */
        $compte = $utilisateurs->tousClubs(['jetonActivation' => Utilisateur::empreinte($jeton)])[0] ?? null;
        if (!$compte || !$compte->isJetonValide()) {
            return $this->render('security/mot_de_passe_lien_invalide.html.twig', [], new Response('', Response::HTTP_GONE));
        }
        $clubCourant->activer($compte->getClub());

        $form = $this->createFormBuilder()
            ->add('motDePasse', RepeatedType::class, [
                'type' => PasswordType::class,
                'first_options' => ['label' => 'Nouveau mot de passe', 'help' => '8 caractères minimum.', 'attr' => ['autocomplete' => 'new-password']],
                'second_options' => ['label' => 'Confirmation', 'attr' => ['autocomplete' => 'new-password']],
                'invalid_message' => 'Les deux mots de passe ne sont pas identiques.',
                'constraints' => [
                    new NotBlank(message: 'Choisissez un mot de passe.'),
                    new Length(min: 8, max: 4096, minMessage: 'Le mot de passe doit faire au moins {{ limit }} caractères.'),
                ],
            ])
            ->getForm();
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $compte->setPassword($hasher->hashPassword($compte, $form->get('motDePasse')->getData()))->effacerJeton();
            $em->flush();
            $security->login($compte, 'form_login', 'main');
            $this->addFlash('succes', 'Mot de passe changé : vous êtes connecté.');

            return $this->redirectToRoute('app_accueil');
        }

        return $this->render('security/mot_de_passe_nouveau.html.twig', ['form' => $form, 'compte' => $compte]);
    }
}
