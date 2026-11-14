<?php

namespace App\Controller;

use App\Entity\ConnexionHelloAsso;
use App\Entity\Utilisateur;
use App\Repository\ConnexionHelloAssoRepository;
use App\Service\Coffre;
use App\Service\ErreurHelloAsso;
use App\Service\HelloAsso;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Paramètres du paiement en ligne (option du forfait) : connexion du compte HelloAsso du club, guidée pour
 * un club qui a déjà un compte comme pour un club qui doit le créer ; vérification immédiate des identifiants.
 */
#[Route('/club/paiement-en-ligne')]
#[IsGranted('ROLE_ADMIN')]
class PaiementEnLigneController extends AbstractController
{
    public function __construct(
        private readonly ConnexionHelloAssoRepository $connexions,
        private readonly EntityManagerInterface $em,
    ) {
    }

    #[Route('', name: 'app_paiement_en_ligne', methods: ['GET', 'POST'])]
    public function parametres(Request $request, Coffre $coffre, HelloAsso $helloAsso): Response
    {
        $club = $this->moi()->getClub();
        $connexion = $this->connexions->findOneBy(['club' => $club]) ?? new ConnexionHelloAsso();
        $form = $this->createFormBuilder([
            'actif' => $club->isPaiementEnLigne(),
            'slug' => $connexion->getSlug(),
            'clientId' => $connexion->getClientId(),
            'test' => $connexion->isTest(),
            'echeancesMax' => $connexion->getEcheancesMax(),
        ])
            ->add('actif', CheckboxType::class, ['label' => 'Proposer le paiement en ligne aux familles', 'required' => false])
            ->add('slug', TextType::class, ['label' => 'Identifiant de l\'association sur HelloAsso', 'required' => false,
                'attr' => ['placeholder' => 'ex. : fc-mon-club', 'autocomplete' => 'off'],
                'help' => 'La fin de l\'adresse de votre page : helloasso.com/associations/fc-mon-club. Vous pouvez coller l\'adresse entière.'])
            ->add('clientId', TextType::class, ['label' => 'Client ID', 'required' => false, 'attr' => ['autocomplete' => 'off']])
            ->add('secret', PasswordType::class, ['label' => 'Client secret', 'required' => false, 'attr' => ['autocomplete' => 'new-password'],
                'help' => $connexion->hasSecret() ? 'Déjà enregistré (chiffré) : laissez vide pour le garder.' : 'Il ne sera plus jamais affiché, ni envoyé ailleurs qu\'à HelloAsso.'])
            ->add('test', CheckboxType::class, ['label' => 'Compte de test (helloasso-sandbox.com)', 'required' => false])
            ->add('echeancesMax', ChoiceType::class, ['label' => 'Paiement en plusieurs fois', 'choices' => array_flip(ConnexionHelloAsso::ECHEANCES),
                'help' => 'Les familles choisissent ensuite, dans la limite fixée ici ; une échéance par mois.'])
            ->getForm();
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $d = $form->getData();
            $club->setPaiementEnLigne((bool) $d['actif']);
            $identifiantsChanges = $connexion->getSlug() !== (new ConnexionHelloAsso())->setSlug($d['slug'])->getSlug()
                || $connexion->getClientId() !== trim((string) $d['clientId']) || $connexion->isTest() !== (bool) $d['test'] || $d['secret'];
            $connexion->setSlug($d['slug'])->setClientId($d['clientId'])->setTest((bool) $d['test'])->setEcheancesMax((int) $d['echeancesMax']);
            if ($d['secret']) {
                $connexion->setSecretChiffre($coffre->chiffrer(trim($d['secret'])));
            }
            if ($identifiantsChanges) {
                $connexion->oublierVerification();
            }
            $complet = $connexion->getSlug() && $connexion->getClientId() && $connexion->hasSecret();
            if ($complet) {
                $this->em->persist($connexion);
            }
            $this->em->flush();

            if ($complet && !$connexion->isVerifiee()) {
                try {
                    $nom = $helloAsso->verifier($connexion);
                    $this->addFlash('succes', \sprintf('✓ Paiement en ligne connecté au compte HelloAsso « %s ».', $nom));
                } catch (ErreurHelloAsso $e) {
                    $form->addError(new FormError($e->getMessage()));

                    return $this->vue($form, $connexion);
                }
            } elseif ($d['actif'] && !$complet) {
                $this->addFlash('info', 'Option activée. Renseignez les identifiants HelloAsso pour que les familles puissent payer en ligne.');
            } else {
                $this->addFlash('succes', 'Réglages enregistrés.');
            }

            return $this->redirectToRoute('app_paiement_en_ligne');
        }

        return $this->vue($form, $connexion);
    }

    /** Oublie le compte HelloAsso (identifiants et jetons) ; l'option reste telle quelle. */
    #[Route('/deconnecter', name: 'app_paiement_en_ligne_deconnecter', methods: ['POST'])]
    public function deconnecter(Request $request): Response
    {
        $connexion = $this->connexions->findOneBy(['club' => $this->moi()->getClub()]);
        if ($connexion && $this->isCsrfTokenValid('deconnecter-helloasso', $request->getPayload()->getString('_token'))) {
            $this->em->remove($connexion);
            $this->em->flush();
            $this->addFlash('succes', 'Compte HelloAsso déconnecté : les familles ne peuvent plus payer en ligne.');
        }

        return $this->redirectToRoute('app_paiement_en_ligne');
    }

    private function vue($form, ConnexionHelloAsso $connexion): Response
    {
        return $this->render('paiement_en_ligne/parametres.html.twig', [
            'form' => $form,
            'connexion' => $connexion,
            'club' => $this->moi()->getClub(),
            'adresse_notification' => $connexion->getId() ? $this->generateUrl('app_helloasso_notification', ['jeton' => $connexion->getJetonNotification()], UrlGeneratorInterface::ABSOLUTE_URL) : null,
        ]);
    }

    private function moi(): Utilisateur
    {
        /** @var Utilisateur $moi */
        $moi = $this->getUser();

        return $moi;
    }
}
