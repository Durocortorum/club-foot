<?php

namespace App\EventSubscriber;

use App\Entity\Utilisateur;
use App\Repository\UtilisateurRepository;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Mailer\Event\MessageEvent;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAccountStatusException;
use Symfony\Component\Security\Http\Event\CheckPassportEvent;

/**
 * Compte suspendu par l'administrateur : la connexion est refusée, une personne déjà connectée est déconnectée
 * à sa page suivante, et les e-mails du site ne lui sont plus envoyés (les autres destinataires les reçoivent).
 */
class CompteSuspendu implements EventSubscriberInterface
{
    public function __construct(
        private readonly Security $security,
        private readonly UrlGeneratorInterface $urls,
        private readonly UtilisateurRepository $utilisateurs,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            CheckPassportEvent::class => ['verifierConnexion', -10],
            // Après le pare-feu (priorité 8) : l'utilisateur connecté est connu.
            KernelEvents::REQUEST => ['deconnecter', 5],
            MessageEvent::class => ['filtrerEmails', 250],
        ];
    }

    public function verifierConnexion(CheckPassportEvent $event): void
    {
        $u = $event->getPassport()->getUser();
        if ($u instanceof Utilisateur && $u->isSuspendu()) {
            throw new CustomUserMessageAccountStatusException(self::message($u));
        }
    }

    public function deconnecter(RequestEvent $event): void
    {
        if (!$event->isMainRequest() || !($u = $this->security->getUser()) instanceof Utilisateur || !$u->isSuspendu()) {
            return;
        }
        $this->security->logout(false);
        $session = $event->getRequest()->getSession();
        if (method_exists($session, 'getFlashBag')) {
            $session->getFlashBag()->add('erreur', self::message($u));
        }
        $event->setResponse(new RedirectResponse($this->urls->generate('app_connexion')));
    }

    public function filtrerEmails(MessageEvent $event): void
    {
        $envelope = $event->getEnvelope();
        $adresses = array_map(fn ($a) => mb_strtolower($a->getAddress()), $envelope->getRecipients());
        $suspendus = [];
        foreach ($adresses ? $this->utilisateurs->tousClubs(['email' => $adresses]) : [] as $u) {
            if ($u->isSuspendu()) {
                $suspendus[] = mb_strtolower((string) $u->getEmail());
            }
        }
        if (!$suspendus) {
            return;
        }
        $gardes = array_values(array_filter($envelope->getRecipients(), fn ($a) => !\in_array(mb_strtolower($a->getAddress()), $suspendus, true)));
        if (!$gardes) {
            $event->reject();

            return;
        }
        $envelope->setRecipients($gardes);
    }

    public static function message(Utilisateur $u): string
    {
        return 'Votre compte est suspendu' . ($u->getSuspenduJusquAu() ? ' jusqu\'au ' . $u->getSuspenduJusquAu()->format('d/m/Y') . ' inclus' : '')
            . '. Pour toute question, adressez-vous à un responsable du club.';
    }
}
