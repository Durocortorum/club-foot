<?php

namespace App\Service;

use App\Entity\DemandeAccesMedical;
use App\Entity\Utilisateur;
use App\Repository\UtilisateurRepository;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Demandes d'accès aux données médicales : qui peut les traiter (médecin du club ou administrateur),
 * et les e-mails envoyés (nouvelle demande aux médecins et administrateurs, réponse au demandeur).
 * Le demandeur ne voit jamais qui est médecin : il est seulement prévenu de la décision.
 */
class DemandesMedicales
{
    public function __construct(
        private readonly Security $security,
        private readonly UtilisateurRepository $utilisateurs,
        private readonly MailerInterface $mailer,
        private readonly UrlGeneratorInterface $urls,
        private readonly AccesFamille $acces,
        private readonly LoggerInterface $logger,
        #[Autowire('%env(MAILER_EXPEDITEUR)%')]
        private readonly string $expediteur,
    ) {
    }

    /** Le compte connecté traite-t-il les demandes ? */
    public function peutTraiter(): bool
    {
        return $this->security->isGranted('ROLE_ADMIN') || $this->security->isGranted('ROLE_MEDECIN');
    }

    /** @return list<Utilisateur> médecins et administrateurs du club, avec une adresse e-mail réelle */
    public function destinataires(): array
    {
        return array_values(array_filter(
            $this->utilisateurs->findBy(['famille' => false]),
            fn (Utilisateur $u) => ($u->isAdmin() || $u->isMedecin()) && !str_ends_with((string) $u->getEmail(), '.invalid'),
        ));
    }

    /** @return int nombre d'e-mails envoyés */
    public function prevenirNouvelle(DemandeAccesMedical $d): int
    {
        $lien = $this->urls->generate('app_utilisateur_droits', [], UrlGeneratorInterface::ABSOLUTE_URL) . '#demandes';
        $texte = \sprintf(
            "Bonjour,\n\n%s demande l'accès « %s » (%s) aux données médicales du club.\n%s\nPour accorder (avec une durée) ou refuser :\n%s\n",
            $d->getDemandeur(), $d->getSectionLibelle(), $d->getNiveauLibelle(),
            $d->getMotif() ? 'Motif : ' . $d->getMotif() . "\n" : '', $lien,
        );
        $envoyes = 0;
        foreach ($this->destinataires() as $u) {
            if ($u === $d->getDemandeur()) {
                continue;
            }
            $envoyes += (int) $this->envoyer($u, 'Demande d\'accès médical : ' . $d->getDemandeur(), $texte);
        }

        return $envoyes;
    }

    public function prevenirDecision(DemandeAccesMedical $d, ?\DateTimeImmutable $echeance): void
    {
        $accordee = DemandeAccesMedical::ACCORDEE === $d->getDecision();
        $this->envoyer(
            $d->getDemandeur(),
            \sprintf('Accès médical %s', $accordee ? 'accordé' : 'refusé'),
            $accordee
                ? \sprintf(
                    "Bonjour %s,\n\nVotre demande d'accès « %s » (%s) aux données médicales est accordée%s.\n\nPour les consulter, un code vous sera envoyé par e-mail à chaque fois :\n%s\n",
                    $d->getDemandeur()->getPrenom(), $d->getSectionLibelle(), $d->getNiveauLibelle(),
                    $echeance ? ' jusqu\'au ' . $echeance->format('d/m/Y') : '',
                    $this->urls->generate('app_medical_code', [], UrlGeneratorInterface::ABSOLUTE_URL),
                )
                : \sprintf(
                    "Bonjour %s,\n\nVotre demande d'accès « %s » (%s) aux données médicales n'a pas été accordée.\nPour en savoir plus, rapprochez-vous du club.\n",
                    $d->getDemandeur()->getPrenom(), $d->getSectionLibelle(), $d->getNiveauLibelle(),
                ),
        );
    }

    private function envoyer(Utilisateur $a, string $sujet, string $texte): bool
    {
        if (!$this->acces->emailActif() || str_ends_with((string) $a->getEmail(), '.invalid')) {
            return false;
        }
        try {
            $this->mailer->send((new Email())
                ->from(new Address($this->expediteur, (string) $a->getClub()?->getNom()))
                ->to((string) $a->getEmail())
                ->subject($sujet)
                ->text($texte));

            return true;
        } catch (TransportExceptionInterface $e) {
            $this->logger->error('E-mail de demande médicale non envoyé à {email} : {erreur}', ['email' => $a->getEmail(), 'erreur' => $e->getMessage()]);

            return false;
        }
    }
}
