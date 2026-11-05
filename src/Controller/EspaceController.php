<?php

namespace App\Controller;

use App\Entity\Utilisateur;
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
    public function index(RencontreRepository $rencontres): Response
    {
        /** @var Utilisateur $moi */
        $moi = $this->getUser();
        if (!$moi->isFamille()) {
            return $this->redirectToRoute('app_accueil');
        }

        $enfants = [];
        foreach ($moi->getJoueurs() as $j) {
            $enfants[] = [
                'joueur' => $j,
                'matchs' => $j->getCategorie() ? $rencontres->aVenir($j->getCategorie(), 5) : [],
            ];
        }

        return $this->render('espace/index.html.twig', ['enfants' => $enfants]);
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
}
