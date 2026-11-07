<?php

namespace App\Service;

use App\Entity\Utilisateur;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Invitation d'un compte de l'encadrement créé sans mot de passe (médecin, accès médical) :
 * un lien valable DUREE_JOURS pour choisir son mot de passe, envoyé par e-mail.
 */
class InvitationCompte
{
    public const DUREE_JOURS = 14;

    public function __construct(
        private readonly MailerInterface $mailer,
        private readonly UrlGeneratorInterface $urls,
        private readonly AccesFamille $acces,
        private readonly LoggerInterface $logger,
        #[Autowire('%env(MAILER_EXPEDITEUR)%')]
        private readonly string $expediteur,
    ) {
    }

    /**
     * Prépare le lien (à appeler avant l'enregistrement du compte) et l'envoie.
     *
     * @param string $presentation phrase qui dit ce que le compte permet (« vous ouvre un compte de médecin du club... »)
     *
     * @return array{lien: string, envoye: bool}
     */
    public function inviter(Utilisateur $compte, string $club, string $sujet, string $presentation): array
    {
        $lien = $this->urls->generate('app_mot_de_passe_nouveau', ['jeton' => $compte->nouveauJeton(self::DUREE_JOURS)], UrlGeneratorInterface::ABSOLUTE_URL);
        if (!$this->acces->emailActif()) {
            return ['lien' => $lien, 'envoye' => false];
        }
        try {
            $this->mailer->send((new Email())
                ->from(new Address($this->expediteur, $club))
                ->to((string) $compte->getEmail())
                ->subject($club . ' : ' . $sujet)
                ->text(\sprintf(
                    "Bonjour,\n\n%s %s\n\nChoisissez votre mot de passe en ouvrant ce lien (personnel, valable %d jours) :\n%s\n\nEnsuite, connectez-vous avec cette adresse e-mail. Pour consulter les données médicales, un code vous sera envoyé par e-mail à chaque fois.\n",
                    $club, $presentation, self::DUREE_JOURS, $lien,
                )));

            return ['lien' => $lien, 'envoye' => true];
        } catch (TransportExceptionInterface $e) {
            $this->logger->error('Invitation non envoyée à {email} : {erreur}', ['email' => $compte->getEmail(), 'erreur' => $e->getMessage()]);

            return ['lien' => $lien, 'envoye' => false];
        }
    }
}
