<?php

namespace App\Service;

use App\Entity\Utilisateur;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

/**
 * Données médicales (Infirmerie, Visite médicale) : en plus du droit, il faut ouvrir l'accès avec un code
 * à 6 chiffres reçu par e-mail. L'accès reste ouvert OUVERTURE_MINUTES, puis un nouveau code est demandé.
 * Tout est gardé dans la session (déconnexion = accès refermé) ; le code n'y est conservé que sous forme d'empreinte.
 */
class AccesMedical
{
    public const OUVERTURE_MINUTES = 30;
    public const CODE_MINUTES = 10;
    public const ESSAIS = 5;
    /** Délai minimum entre deux envois de code, contre les envois en rafale. */
    public const DELAI_RENVOI_SECONDES = 60;

    private const CLE = 'acces_medical';

    public function __construct(
        private readonly Security $security,
        private readonly RequestStack $requests,
        private readonly MailerInterface $mailer,
        private readonly LoggerInterface $logger,
        #[Autowire('%env(MAILER_EXPEDITEUR)%')]
        private readonly string $expediteur,
        #[Autowire('%kernel.secret%')]
        private readonly string $secret,
    ) {
    }

    /** Le compte connecté a-t-il un droit médical en vigueur ? */
    public function aLeDroit(): bool
    {
        return $this->security->isGranted('ROLE_INFIRMERIE_LECTURE') || $this->security->isGranted('ROLE_VISITE_MEDICALE_LECTURE');
    }

    /** Le compte connecté a le droit ET a ouvert l'accès avec un code encore valable. */
    public function estOuvert(): bool
    {
        $fin = $this->ouvertJusquA();

        return $this->aLeDroit() && null !== $fin && $fin > new \DateTimeImmutable();
    }

    public function ouvertJusquA(): ?\DateTimeImmutable
    {
        $etat = $this->etat();

        return isset($etat['ouvert_jusqu_a']) ? (new \DateTimeImmutable())->setTimestamp($etat['ouvert_jusqu_a']) : null;
    }

    /**
     * Envoie un nouveau code à l'adresse du compte.
     *
     * @return string|null message d'erreur, ou null si le code est parti
     */
    public function envoyerCode(Utilisateur $moi): ?string
    {
        $etat = $this->etat();
        if (isset($etat['envoye_le']) && time() - $etat['envoye_le'] < self::DELAI_RENVOI_SECONDES) {
            return 'Un code vient d\'être envoyé : attendez une minute avant d\'en demander un autre.';
        }
        if (str_ends_with((string) $moi->getEmail(), '.invalid')) {
            return 'Votre compte n\'a pas d\'adresse e-mail réelle : demandez à l\'administrateur de la corriger pour recevoir le code.';
        }

        $code = str_pad((string) random_int(0, 999999), 6, '0', \STR_PAD_LEFT);
        try {
            $this->mailer->send((new Email())
                ->from(new Address($this->expediteur, (string) $moi->getClub()?->getNom()))
                ->to((string) $moi->getEmail())
                ->subject(\sprintf('Code d\'accès aux données médicales : %s', $code))
                ->text(\sprintf(
                    "Bonjour %s,\n\nVotre code d'accès aux données médicales est : %s\n\nIl est valable %d minutes. Une fois saisi, l'accès reste ouvert %d minutes.\n\nVous n'avez rien demandé ? Quelqu'un connaît peut-être votre mot de passe : changez-le et prévenez l'administrateur du club.\n",
                    $moi->getPrenom(), $code, self::CODE_MINUTES, self::OUVERTURE_MINUTES,
                )));
        } catch (TransportExceptionInterface $e) {
            $this->logger->error('Code médical non envoyé à {email} : {erreur}', ['email' => $moi->getEmail(), 'erreur' => $e->getMessage()]);

            return 'L\'e-mail n\'a pas pu partir. Réessayez dans un instant.';
        }

        $etat['code'] = $this->empreinte($code);
        $etat['code_jusqu_a'] = time() + self::CODE_MINUTES * 60;
        $etat['essais'] = self::ESSAIS;
        $etat['envoye_le'] = time();
        $this->enregistrer($etat);

        return null;
    }

    /**
     * Vérifie le code saisi ; s'il est bon, ouvre l'accès pour OUVERTURE_MINUTES.
     *
     * @return string|null message d'erreur, ou null si l'accès est ouvert
     */
    public function verifierCode(string $saisi): ?string
    {
        $etat = $this->etat();
        if (!isset($etat['code']) || $etat['code_jusqu_a'] < time()) {
            return 'Ce code a expiré ou n\'a pas été demandé : demandez-en un nouveau.';
        }
        if (!hash_equals($etat['code'], $this->empreinte(preg_replace('/\D/', '', $saisi)))) {
            --$etat['essais'];
            if ($etat['essais'] <= 0) {
                unset($etat['code'], $etat['code_jusqu_a'], $etat['essais']);
                $this->enregistrer($etat);

                return 'Code incorrect. Trop d\'essais : demandez un nouveau code.';
            }
            $this->enregistrer($etat);

            return \sprintf('Code incorrect. Il vous reste %d essai%s.', $etat['essais'], $etat['essais'] > 1 ? 's' : '');
        }

        $this->enregistrer(['ouvert_jusqu_a' => time() + self::OUVERTURE_MINUTES * 60]);

        return null;
    }

    /** Referme l'accès tout de suite. */
    public function fermer(): void
    {
        $this->requests->getSession()->remove(self::CLE);
    }

    private function empreinte(string $code): string
    {
        // L'empreinte est rangée dans la session : un code ne sert que dans la session qui l'a demandé.
        return hash_hmac('sha256', $code, $this->secret);
    }

    /** @return array<string, mixed> */
    private function etat(): array
    {
        $requete = $this->requests->getCurrentRequest();
        if (!$requete || !$requete->hasSession()) {
            return [];
        }

        return $requete->getSession()->get(self::CLE, []);
    }

    /** @param array<string, mixed> $etat */
    private function enregistrer(array $etat): void
    {
        $this->requests->getSession()->set(self::CLE, $etat);
    }
}
