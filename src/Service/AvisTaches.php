<?php

namespace App\Service;

use App\Entity\Tache;
use App\Entity\Utilisateur;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * E-mails des tâches : à la personne de l'encadrement chargée, à la famille du joueur chargé,
 * et à la personne qui a confié la tâche quand elle est faite.
 */
class AvisTaches
{
    public function __construct(
        private readonly MailerInterface $mailer,
        private readonly CourrielClub $courriel,
        private readonly AccesFamille $acces,
        private readonly UrlGeneratorInterface $urls,
        private readonly LoggerInterface $logger,
    ) {
    }

    /** E-mail à la personne chargée, sauf si elle se confie la tâche à elle-même. */
    public function responsable(Tache $tache, Utilisateur $par): bool
    {
        $responsable = $tache->getResponsable();
        if (!$responsable || $responsable === $par) {
            return false;
        }

        return $this->envoyer($tache, $responsable, 'Nouvelle tâche : ' . $tache->getObjet(), [
            'titre' => 'Une tâche vous est confiée',
            'salutation' => 'Bonjour ' . $responsable->getPrenom() . ',',
            'paragraphes' => [self::nom($par) . ' vous confie la tâche suivante.'],
            'encadre' => $tache->getObjet(),
            'details' => [
                'Catégorie' => $tache->getCategorie()?->getNom() ?? 'Tout le club',
                'À faire pour le' => $tache->getEcheance()?->format('d/m/Y'),
                'Urgente' => $tache->isUrgente() ? 'oui' : null,
                'Détails' => $tache->getDescription(),
            ],
            'bouton' => ['libelle' => 'Voir la tâche', 'url' => $this->urls->generate('app_tache_voir', ['id' => $tache->getId()], UrlGeneratorInterface::ABSOLUTE_URL)],
        ]);
    }

    /**
     * E-mail aux comptes famille actifs du joueur chargé ; la tâche est aussi affichée dans leur espace.
     *
     * @return int nombre de comptes prévenus
     */
    public function famille(Tache $tache, Utilisateur $par): int
    {
        $joueur = $tache->getJoueur();
        if (!$joueur) {
            return 0;
        }
        $prevenus = 0;
        foreach ($this->acces->comptes($joueur) as $compte) {
            if (null === $compte->getPassword()) {
                continue;
            }
            $prevenus += (int) $this->envoyer($tache, $compte, 'Tâche pour ' . $joueur->getPrenom() . ' : ' . $tache->getObjet(), [
                'titre' => 'Une tâche est confiée à ' . $joueur->getPrenom(),
                'paragraphes' => [self::nom($par) . ' confie à ' . $joueur->getPrenom() . ' ' . $joueur->getNom() . ' la tâche suivante.'],
                'encadre' => $tache->getObjet(),
                'details' => [
                    'Équipe' => $tache->getCategorie()?->getNom(),
                    'À faire pour le' => $tache->getEcheance()?->format('d/m/Y'),
                    'Détails' => $tache->getDescription(),
                ],
                'bouton' => ['libelle' => 'Voir dans mon espace', 'url' => $this->urls->generate('app_espace', [], UrlGeneratorInterface::ABSOLUTE_URL) . '#taches'],
                'note' => 'Une fois la tâche faite, indiquez-le depuis votre espace : l\'encadrement en sera informé.',
            ]);
        }

        return $prevenus;
    }

    /** E-mail à la personne qui a confié la tâche quand quelqu'un d'autre la termine. */
    public function faite(Tache $tache, Utilisateur $par, ?string $parLibelle = null): void
    {
        $auteur = $tache->getCreePar();
        if (!$auteur || $auteur === $par) {
            return;
        }
        $this->envoyer($tache, $auteur, 'Tâche faite : ' . $tache->getObjet(), [
            'titre' => 'Tâche faite',
            'salutation' => 'Bonjour ' . $auteur->getPrenom() . ',',
            'paragraphes' => [($parLibelle ?? self::nom($par)) . ' a terminé la tâche que vous aviez confiée.'],
            'encadre' => $tache->getObjet(),
            'details' => ['Compte rendu' => $tache->getCompteRendu()],
            'bouton' => ['libelle' => 'Voir la tâche', 'url' => $this->urls->generate('app_tache_voir', ['id' => $tache->getId()], UrlGeneratorInterface::ABSOLUTE_URL)],
        ]);
    }

    /** @param array<string, mixed> $contenu */
    private function envoyer(Tache $tache, Utilisateur $a, string $sujet, array $contenu): bool
    {
        try {
            $this->mailer->send($this->courriel->message($tache->getClub(), (string) $a->getEmail(), $sujet, $contenu));

            return true;
        } catch (TransportExceptionInterface $e) {
            $this->logger->error('Tâche : e-mail non envoyé à {email} : {erreur}', ['email' => $a->getEmail(), 'erreur' => $e->getMessage()]);

            return false;
        }
    }

    private static function nom(Utilisateur $u): string
    {
        return $u->getPrenom() . ' ' . $u->getNom();
    }
}
