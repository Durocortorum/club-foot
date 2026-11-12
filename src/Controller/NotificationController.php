<?php

namespace App\Controller;

use App\Entity\Utilisateur;
use App\Service\Apparence;
use App\Service\NotificationsTelephone;
use Symfony\Component\Asset\Packages;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Notifications sur le téléphone : abonnement d'un appareil (depuis Mon espace ou Mon compte), essai,
 * et manifeste qui permet d'installer l'application sur l'écran d'accueil (indispensable sur iPhone).
 */
class NotificationController extends AbstractController
{
    public function __construct(private readonly NotificationsTelephone $notifications)
    {
    }

    #[Route('/notifications/abonnement', name: 'app_notifications_abonner', methods: ['POST'])]
    public function abonner(Request $request): JsonResponse
    {
        $donnees = $this->lire($request);
        $adresse = (string) ($donnees['endpoint'] ?? '');
        $p256dh = (string) ($donnees['keys']['p256dh'] ?? '');
        $auth = (string) ($donnees['keys']['auth'] ?? '');
        if (!str_starts_with($adresse, 'https://') || \strlen($adresse) > 2000 || !preg_match('/^[\w-]{40,120}$/', $p256dh) || !preg_match('/^[\w-]{10,60}$/', $auth)) {
            return new JsonResponse(['erreur' => 'Abonnement invalide.'], 400);
        }
        $this->notifications->abonner($this->moi(), $adresse, $p256dh, $auth, self::appareil((string) $request->headers->get('User-Agent')));

        return new JsonResponse(['ok' => true]);
    }

    #[Route('/notifications/desabonnement', name: 'app_notifications_desabonner', methods: ['POST'])]
    public function desabonner(Request $request): JsonResponse
    {
        $donnees = $this->lire($request);
        $this->notifications->desabonner($this->moi(), (string) ($donnees['endpoint'] ?? ''));

        return new JsonResponse(['ok' => true]);
    }

    /** Envoie une notification d'essai aux appareils de l'utilisateur. */
    #[Route('/notifications/essai', name: 'app_notifications_essai', methods: ['POST'])]
    public function essai(Request $request): JsonResponse
    {
        $this->lire($request);
        $moi = $this->moi();
        $n = $this->notifications->envoyer([$moi], $moi->getClub()?->getNom() ?? 'Club', 'Les notifications fonctionnent sur cet appareil.', $this->generateUrl($moi->isFamille() ? 'app_espace' : 'app_accueil', [], UrlGeneratorInterface::ABSOLUTE_URL));

        return new JsonResponse(['ok' => $n > 0, 'appareils' => $n]);
    }

    /** Manifeste de l'application : nom, couleurs et logo du club (ou ceux de l'application si personne n'est connecté). */
    #[Route('/manifest.webmanifest', name: 'app_manifeste', methods: ['GET'])]
    public function manifeste(Apparence $apparence, Packages $packages): Response
    {
        $club = $apparence->club();
        $icones = $club->getLogo()
            ? [['src' => $this->generateUrl('app_club_logo', ['version' => $club->getVersion()]), 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'any']]
            : [['src' => $packages->getUrl('images/logo.svg'), 'sizes' => 'any', 'type' => 'image/svg+xml', 'purpose' => 'any']];
        $reponse = new JsonResponse([
            'name' => $club->getNom() ?: 'Club Foot',
            'short_name' => mb_substr($club->getNom() ?: 'Club Foot', 0, 12),
            'start_url' => '/',
            'scope' => '/',
            'display' => 'standalone',
            'background_color' => '#ffffff',
            'theme_color' => $club->getCouleur(),
            'lang' => 'fr',
            'icons' => $icones,
        ], 200, ['Content-Type' => 'application/manifest+json']);
        $reponse->setEncodingOptions(\JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES);

        return $reponse;
    }

    /** @return array<string, mixed> corps JSON de la requête, après vérification du jeton anti-falsification */
    private function lire(Request $request): array
    {
        $donnees = json_decode($request->getContent(), true);
        if (!\is_array($donnees) || !$this->isCsrfTokenValid('notifications', (string) ($donnees['_token'] ?? ''))) {
            throw $this->createAccessDeniedException();
        }

        return $donnees;
    }

    private function moi(): Utilisateur
    {
        /** @var Utilisateur $moi */
        $moi = $this->getUser();

        return $moi;
    }

    private static function appareil(string $agent): string
    {
        return match (true) {
            str_contains($agent, 'iPhone') => 'iPhone',
            str_contains($agent, 'iPad') => 'iPad',
            str_contains($agent, 'Android') => 'Android',
            str_contains($agent, 'Windows') => 'Ordinateur Windows',
            str_contains($agent, 'Macintosh') => 'Mac',
            default => 'Appareil',
        };
    }
}
