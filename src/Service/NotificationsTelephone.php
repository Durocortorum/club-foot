<?php

namespace App\Service;

use App\Entity\AbonnementPush;
use App\Entity\Utilisateur;
use App\Repository\AbonnementPushRepository;
use Doctrine\ORM\EntityManagerInterface;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Notifications sur le téléphone (Web Push) : l'application installée sur l'écran d'accueil reçoit une alerte même
 * fermée. Chaque compte abonne ses appareils depuis son espace ; les clés VAPID du site sont dans .env.local
 * (VAPID_CLE_PUBLIQUE, VAPID_CLE_PRIVEE). Sans ces clés, rien n'est proposé ni envoyé.
 * En test, les notifications ne partent pas : elles sont gardées dans $envoyees.
 */
class NotificationsTelephone
{
    /** @var list<array{compte: string, titre: string, texte: string, url: string}> notifications « envoyées » en test */
    public static array $envoyees = [];

    public function __construct(
        private readonly AbonnementPushRepository $abonnements,
        private readonly EntityManagerInterface $em,
        private readonly LoggerInterface $logger,
        #[Autowire('%env(default::VAPID_CLE_PUBLIQUE)%')]
        private readonly ?string $clePublique,
        #[Autowire('%env(default::VAPID_CLE_PRIVEE)%')]
        private readonly ?string $clePrivee,
        #[Autowire('%env(MAILER_EXPEDITEUR)%')]
        private readonly string $contact,
        #[Autowire('%kernel.environment%')]
        private readonly string $environnement,
    ) {
    }

    public function active(): bool
    {
        return (bool) $this->clePublique && (bool) $this->clePrivee;
    }

    public function clePublique(): ?string
    {
        return $this->active() ? $this->clePublique : null;
    }

    /** Enregistre (ou reprend) l'abonnement d'un appareil pour ce compte. */
    public function abonner(Utilisateur $compte, string $adresse, string $cleP256dh, string $cleAuth, ?string $appareil): AbonnementPush
    {
        $abonnement = $this->abonnements->parAdresse($adresse);
        if ($abonnement) {
            $abonnement->setUtilisateur($compte)->setCles($cleP256dh, $cleAuth);
        } else {
            $abonnement = new AbonnementPush($compte, $adresse, $cleP256dh, $cleAuth, $appareil);
            $this->em->persist($abonnement);
        }
        $this->em->flush();

        return $abonnement;
    }

    public function desabonner(Utilisateur $compte, string $adresse): void
    {
        $abonnement = $this->abonnements->parAdresse($adresse);
        if ($abonnement && $abonnement->getUtilisateur() === $compte) {
            $this->em->remove($abonnement);
            $this->em->flush();
        }
    }

    /** @return list<AbonnementPush> */
    public function appareils(Utilisateur $compte): array
    {
        return $this->abonnements->deComptes([$compte]);
    }

    /**
     * Envoie une notification à tous les appareils abonnés de ces comptes ; les abonnements expirés sont supprimés.
     *
     * @param iterable<Utilisateur> $comptes
     *
     * @return int appareils prévenus
     */
    public function envoyer(iterable $comptes, string $titre, string $texte, string $url): int
    {
        if (!$this->active()) {
            return 0;
        }
        $abonnements = $this->abonnements->deComptes($comptes);
        if (!$abonnements) {
            return 0;
        }
        $charge = json_encode([
            'titre' => mb_substr($titre, 0, 80),
            'texte' => mb_strlen($texte) > 180 ? mb_substr($texte, 0, 177) . '…' : $texte,
            'url' => $url,
        ], \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES);

        if ('test' === $this->environnement) {
            foreach ($abonnements as $a) {
                self::$envoyees[] = ['compte' => (string) $a->getUtilisateur()->getEmail()] + json_decode($charge, true);
            }

            return \count($abonnements);
        }

        try {
            $webPush = new WebPush(['VAPID' => [
                'subject' => 'mailto:' . $this->contact,
                'publicKey' => $this->clePublique,
                'privateKey' => $this->clePrivee,
            ]], ['TTL' => 86400, 'urgency' => 'normal'], 8);
            $parAdresse = [];
            foreach ($abonnements as $a) {
                $parAdresse[$a->getAdresse()] = $a;
                $webPush->queueNotification(Subscription::create([
                    'endpoint' => $a->getAdresse(),
                    'publicKey' => $a->getCleP256dh(),
                    'authToken' => $a->getCleAuth(),
                    'contentEncoding' => 'aes128gcm',
                ]), $charge);
            }
            $envoyes = 0;
            foreach ($webPush->flush() as $rapport) {
                if ($rapport->isSuccess()) {
                    ++$envoyes;
                } elseif ($rapport->isSubscriptionExpired() && isset($parAdresse[$rapport->getEndpoint()])) {
                    // Application désinstallée ou notifications refusées depuis : on oublie cet appareil.
                    $this->em->remove($parAdresse[$rapport->getEndpoint()]);
                } else {
                    $this->logger->warning('Notification non envoyée : {raison}', ['raison' => $rapport->getReason()]);
                }
            }
            $this->em->flush();

            return $envoyes;
        } catch (\Throwable $e) {
            // Une notification qui échoue ne doit jamais empêcher l'envoi des e-mails ni l'enregistrement.
            $this->logger->error('Notifications impossibles : {erreur}', ['erreur' => $e->getMessage()]);

            return 0;
        }
    }
}
