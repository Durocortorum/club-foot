<?php

namespace App\Service;

use App\Entity\Club;
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
        private readonly CourrielClub $courriel,
    ) {
    }

    /**
     * Prépare le lien (à appeler avant l'enregistrement du compte) et l'envoie.
     *
     * @param string $presentation phrase qui dit ce que le compte permet (« vous ouvre un compte de médecin du club... »)
     *
     * @return array{lien: string, envoye: bool}
     */
    public function inviter(Utilisateur $compte, Club $club, string $sujet, string $presentation): array
    {
        $lien = $this->urls->generate('app_mot_de_passe_nouveau', ['jeton' => $compte->nouveauJeton(self::DUREE_JOURS)], UrlGeneratorInterface::ABSOLUTE_URL);
        if (!$this->acces->emailActif()) {
            return ['lien' => $lien, 'envoye' => false];
        }
        try {
            $this->mailer->send($this->courriel->message($club, (string) $compte->getEmail(), $club->getNom() . ' : ' . $sujet, [
                'titre' => mb_strtoupper(mb_substr($sujet, 0, 1)) . mb_substr($sujet, 1),
                'paragraphes' => [$club->getNom() . ' ' . $presentation, 'Pour commencer, choisissez votre mot de passe :'],
                'bouton' => ['libelle' => 'Choisir mon mot de passe', 'url' => $lien],
                'note' => \sprintf("Ce lien est personnel et valable %d jours. Ensuite, connectez-vous avec cette adresse e-mail.\nPour consulter les données médicales, un code vous sera envoyé par e-mail à chaque fois.", self::DUREE_JOURS),
            ]));

            return ['lien' => $lien, 'envoye' => true];
        } catch (TransportExceptionInterface $e) {
            $this->logger->error('Invitation non envoyée à {email} : {erreur}', ['email' => $compte->getEmail(), 'erreur' => $e->getMessage()]);

            return ['lien' => $lien, 'envoye' => false];
        }
    }
}
