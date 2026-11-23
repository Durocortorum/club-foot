<?php

namespace App\Controller;

use App\Entity\Rencontre;
use App\Entity\Utilisateur;
use App\Security\ActiviteVoter;
use App\Service\CompositionOnze;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * « Composer le onze » d'un match : formation, joueurs disponibles et leurs performances par poste, absences,
 * comparaison avec les compositions précédentes ; le onze validé est reporté sur la feuille de match.
 */
class OnzeController extends AbstractController
{
    #[Route('/matchs/{id}/onze', name: 'app_onze', requirements: ['id' => '\d+'], methods: ['GET'])]
    #[IsGranted(ActiviteVoter::GERER, 'rencontre')]
    public function composer(Rencontre $rencontre, CompositionOnze $onze): Response
    {
        if ($rencontre->isPlateau()) {
            throw $this->createNotFoundException();
        }

        return $this->render('onze/composer.html.twig', [
            'rencontre' => $rencontre,
            'donnees' => $onze->donnees($rencontre, $this->isGranted('ROLE_INFIRMERIE_LECTURE')),
            'modifiable' => $this->isGranted(ActiviteVoter::MODIFIER_MATCH, $rencontre),
        ]);
    }

    #[Route('/matchs/{id}/onze', name: 'app_onze_valider', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[IsGranted(ActiviteVoter::MODIFIER_MATCH, 'rencontre')]
    public function valider(Request $request, Rencontre $rencontre, CompositionOnze $onze, EntityManagerInterface $em): JsonResponse
    {
        $donnees = json_decode($request->getContent(), true);
        if (!\is_array($donnees) || !$this->isCsrfTokenValid('onze' . $rencontre->getId(), (string) ($donnees['_token'] ?? ''))) {
            return $this->json(['erreur' => 'La page a expiré : rechargez-la puis validez à nouveau.'], 400);
        }
        $titulaires = [];
        foreach ((array) ($donnees['titulaires'] ?? []) as $t) {
            if (\is_array($t) && is_numeric($t['joueur'] ?? null) && is_numeric($t['x'] ?? null) && is_numeric($t['y'] ?? null)) {
                $titulaires[] = ['joueur' => (int) $t['joueur'], 'x' => (float) $t['x'], 'y' => (float) $t['y'], 'poste' => (string) ($t['poste'] ?? '')];
            }
        }
        $remplacants = array_values(array_map('intval', array_filter((array) ($donnees['remplacants'] ?? []), 'is_numeric')));
        if ($erreur = $onze->valider($rencontre, (string) ($donnees['systeme'] ?? ''), $titulaires, $remplacants)) {
            return $this->json(['erreur' => $erreur], 400);
        }
        if (($moi = $this->getUser()) instanceof Utilisateur) {
            $rencontre->marquerModifie($moi);
        }
        $em->flush();
        $this->addFlash('succes', \sprintf('Onze de départ validé : %d titulaires et %d remplaçants sur la feuille de match.', \count($titulaires), \count($remplacants)));

        return $this->json(['retour' => $this->generateUrl('app_rencontre_voir', ['id' => $rencontre->getId()])]);
    }
}
