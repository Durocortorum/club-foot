<?php

namespace App\Service;

use App\Entity\Joueur;
use App\Entity\Utilisateur;
use App\Repository\UtilisateurRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Comptes famille : un joueur ou ses parents reçoivent un lien pour choisir leur mot de passe,
 * puis se connectent à leur espace (convocations, calendrier...). Une même adresse peut suivre
 * plusieurs joueurs (fratrie) ; elle ne peut pas être celle d'un compte de l'encadrement.
 */
class AccesFamille
{
    public const DUREE_LIEN = 14;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly UtilisateurRepository $utilisateurs,
        private readonly MailerInterface $mailer,
        private readonly UrlGeneratorInterface $urls,
        private readonly LoggerInterface $logger,
        #[Autowire('%env(MAILER_DSN)%')]
        private readonly string $mailerDsn,
        #[Autowire('%env(MAILER_EXPEDITEUR)%')]
        private readonly string $expediteur,
    ) {
    }

    /** L'envoi d'e-mails est-il réglé (MAILER_DSN) ? Sinon, les liens sont à transmettre à la main. */
    public function emailActif(): bool
    {
        // « null://null » : réglage par défaut, rien n'est envoyé. Les tests utilisent « null://default »,
        // un transport qui n'envoie rien non plus mais laisse vérifier les e-mails préparés.
        return 'null://null' !== $this->mailerDsn;
    }

    /**
     * Adresses de la fiche joueur qui peuvent recevoir une invitation : celle du joueur et celles des deux parents.
     *
     * @return array<string, string> adresse => « joueur », « parent1 » ou « parent2 »
     */
    public function adresses(Joueur $joueur): array
    {
        $adresses = [];
        foreach (['joueur' => $joueur->getEmailJoueur(), 'parent1' => $joueur->getEmailParent1(), 'parent2' => $joueur->getEmailParent2()] as $qui => $email) {
            $email = mb_strtolower(trim((string) $email));
            if (filter_var($email, \FILTER_VALIDATE_EMAIL)) {
                $adresses[$email] ??= $qui;
            }
        }

        return $adresses;
    }

    /** @return list<Utilisateur> comptes famille qui suivent ce joueur */
    public function comptes(Joueur $joueur): array
    {
        return $this->utilisateurs->createQueryBuilder('u')
            ->andWhere(':joueur MEMBER OF u.joueurs')->setParameter('joueur', $joueur)
            ->orderBy('u.email')
            ->getQuery()
            ->getResult();
    }

    /**
     * Accès famille d'un joueur : comptes qui le suivent et adresses de la fiche encore sans compte.
     * Un lien expiré se renvoie depuis la fiche, adresse par adresse.
     * Joueur majeur : le club n'invite que lui ; c'est lui qui ouvre l'accès à ses proches (voir ProchesJoueur).
     *
     * @return array{comptes: list<Utilisateur>, a_inviter: array<string, string>, majeur: bool}
     */
    public function etat(Joueur $joueur): array
    {
        $comptes = $this->comptes($joueur);
        $aInviter = $this->adresses($joueur);
        foreach ($comptes as $c) {
            unset($aInviter[$c->getEmail()]);
        }
        $majeur = !$joueur->isMineur();
        if ($majeur) {
            $aInviter = array_filter($aInviter, fn (string $qui) => 'joueur' === $qui);
        }

        return ['comptes' => $comptes, 'a_inviter' => $aInviter, 'majeur' => $majeur];
    }

    /** Ce compte est-il celui du joueur lui-même (adresse e-mail du joueur sur sa fiche) ? */
    public function estLeJoueur(Utilisateur $compte, Joueur $joueur): bool
    {
        return 'joueur' === ($this->adresses($joueur)[$compte->getEmail()] ?? null);
    }

    /** Le joueur connecté est majeur et décide lui-même de l'accès de ses parents. */
    public function decideAccesParents(Utilisateur $compte, Joueur $joueur): bool
    {
        return $compte->isFamille() && $compte->suit($joueur) && !$joueur->isMineur() && $this->estLeJoueur($compte, $joueur);
    }

    /**
     * Donne l'accès famille à cette adresse pour ce joueur : crée le compte si besoin, ou rattache
     * le joueur au compte existant (fratrie). Tant que le mot de passe n'est pas choisi, un nouveau
     * lien d'activation est préparé et envoyé par e-mail quand l'envoi est réglé.
     *
     * @return array{compte: Utilisateur, lien: ?string, envoye: bool}
     *
     * @throws \DomainException si l'adresse ne peut pas servir à un compte famille de ce club
     */
    public function inviter(Joueur $joueur, string $email, string $qui = 'parent1'): array
    {
        $email = mb_strtolower(trim($email));
        if (!filter_var($email, \FILTER_VALIDATE_EMAIL)) {
            throw new \DomainException(\sprintf('« %s » n\'est pas une adresse e-mail valide.', $email));
        }
        $compte = $this->utilisateurs->tousClubs(['email' => $email])[0] ?? null;
        if ($compte && $compte->getClub()->getId() !== $joueur->getClub()->getId()) {
            throw new \DomainException(\sprintf('L\'adresse %s est déjà utilisée par un autre club.', $email));
        }
        if ($compte && !$compte->isFamille()) {
            throw new \DomainException(\sprintf('L\'adresse %s est celle d\'un compte de l\'encadrement : indiquez une autre adresse pour l\'accès famille.', $email));
        }
        if (!$compte) {
            $compte = (new Utilisateur())
                ->setClub($joueur->getClub())
                ->setFamille(true)
                ->setEmail($email)
                ->setNom($joueur->getNom())
                ->setPrenom('joueur' === $qui ? $joueur->getPrenom() : 'Famille');
            $this->em->persist($compte);
        }
        $compte->addJoueur($joueur);

        $lien = null;
        $envoye = false;
        if (!$compte->getPassword()) {
            $lien = $this->urls->generate('app_activation', ['jeton' => $compte->nouveauJeton(self::DUREE_LIEN)], UrlGeneratorInterface::ABSOLUTE_URL);
            $envoye = $this->envoyer($compte, $joueur, $lien);
        }
        $this->em->flush();

        return ['compte' => $compte, 'lien' => $lien, 'envoye' => $envoye];
    }

    /** Le compte ne suit plus ce joueur ; supprimé s'il ne suit plus personne. */
    public function retirer(Joueur $joueur, Utilisateur $compte): void
    {
        $compte->removeJoueur($joueur);
        if ($compte->getJoueurs()->isEmpty()) {
            $this->em->remove($compte);
        }
        $this->em->flush();
    }

    private function envoyer(Utilisateur $compte, Joueur $joueur, string $lien): bool
    {
        if (!$this->emailActif()) {
            return false;
        }
        $club = $joueur->getClub();
        $message = (new TemplatedEmail())
            ->from(new Address($this->expediteur, $club->getNom()))
            ->to($compte->getEmail())
            ->subject(\sprintf('%s : votre accès à l\'espace famille', $club->getNom()))
            ->htmlTemplate('emails/invitation.html.twig')
            ->textTemplate('emails/invitation.txt.twig')
            ->context(['club' => $club, 'joueur' => $joueur, 'lien' => $lien, 'jours' => self::DUREE_LIEN]);
        try {
            $this->mailer->send($message);

            return true;
        } catch (TransportExceptionInterface $e) {
            $this->logger->error('Invitation famille non envoyée à {email} : {erreur}', ['email' => $compte->getEmail(), 'erreur' => $e->getMessage()]);

            return false;
        }
    }
}
