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
        private readonly CourrielClub $courriel,
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
        $contenu = [
            'titre' => '🔒 Demande d\'accès médical',
            'paragraphes' => [\sprintf('%s demande l\'accès aux données médicales du club.', $d->getDemandeur())],
            'details' => ['Accès' => $d->getSectionLibelle(), 'Niveau' => $d->getNiveauLibelle(), 'Motif' => $d->getMotif()],
            'bouton' => ['libelle' => 'Accorder ou refuser', 'url' => $lien],
            'note' => 'Vous choisissez la durée de l\'accès en l\'accordant.',
        ];
        $envoyes = 0;
        foreach ($this->destinataires() as $u) {
            if ($u === $d->getDemandeur()) {
                continue;
            }
            $envoyes += (int) $this->envoyer($u, 'Demande d\'accès médical : ' . $d->getDemandeur(), $contenu);
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
                ? [
                    'titre' => '🔓 Accès médical accordé',
                    'salutation' => \sprintf('Bonjour %s,', $d->getDemandeur()->getPrenom()),
                    'paragraphes' => [\sprintf('Votre demande d\'accès « %s » (%s) aux données médicales est accordée%s.',
                        $d->getSectionLibelle(), $d->getNiveauLibelle(), $echeance ? ' jusqu\'au ' . $echeance->format('d/m/Y') : '')],
                    'bouton' => ['libelle' => 'Consulter', 'url' => $this->urls->generate('app_medical_code', [], UrlGeneratorInterface::ABSOLUTE_URL)],
                    'note' => 'Pour consulter les données médicales, un code vous sera envoyé par e-mail à chaque fois.',
                ]
                : [
                    'titre' => 'Accès médical non accordé',
                    'salutation' => \sprintf('Bonjour %s,', $d->getDemandeur()->getPrenom()),
                    'paragraphes' => [
                        \sprintf('Votre demande d\'accès « %s » (%s) aux données médicales n\'a pas été accordée.', $d->getSectionLibelle(), $d->getNiveauLibelle()),
                        'Pour en savoir plus, rapprochez-vous du club.',
                    ],
                ],
        );
    }

    /** @param array<string, mixed> $contenu voir CourrielClub::message() */
    private function envoyer(Utilisateur $a, string $sujet, array $contenu): bool
    {
        if (!$this->acces->emailActif() || str_ends_with((string) $a->getEmail(), '.invalid')) {
            return false;
        }
        try {
            $this->mailer->send($this->courriel->message($a->getClub(), (string) $a->getEmail(), $sujet, $contenu));

            return true;
        } catch (TransportExceptionInterface $e) {
            $this->logger->error('E-mail de demande médicale non envoyé à {email} : {erreur}', ['email' => $a->getEmail(), 'erreur' => $e->getMessage()]);

            return false;
        }
    }
}
