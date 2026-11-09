<?php

namespace App\Service;

use App\Entity\Joueur;
use App\Entity\Message;
use App\Entity\MessageReponse;
use App\Entity\Utilisateur;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Messagerie interne : envoi d'un message aux familles des joueurs choisis (un destinataire par compte famille,
 * prévenu par e-mail s'il est actif), et avis par e-mail des réponses (famille → auteur, encadrement → famille).
 */
class Messagerie
{
    public function __construct(
        private readonly AccesFamille $acces,
        private readonly EntityManagerInterface $em,
        private readonly MailerInterface $mailer,
        private readonly CourrielClub $courriel,
        private readonly UrlGeneratorInterface $urls,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @param iterable<Joueur>      $joueurs familles destinataires
     * @param iterable<Utilisateur> $collegues membres de l'encadrement destinataires
     *
     * @return array{emails: int, sans_compte: list<Joueur>, echecs: list<string>}
     */
    public function envoyer(Message $message, iterable $joueurs, bool $parEmail, iterable $collegues = []): array
    {
        $resultat = ['emails' => 0, 'sans_compte' => [], 'echecs' => []];
        $prevenus = [];
        foreach ($collegues as $compte) {
            $message->ajouterDestinataire(null, $compte);
            $prevenus[$compte->getId()] = $compte;
        }
        foreach ($joueurs as $joueur) {
            $comptes = $this->acces->comptes($joueur);
            foreach ($comptes as $compte) {
                $message->ajouterDestinataire($joueur, $compte);
            }
            if (!$comptes) {
                $message->ajouterDestinataire($joueur, null);
            }
            // Compte invité mais pas encore activé : il verra le message à l'activation, mais rien ne le prévient.
            $actifs = array_filter($comptes, fn (Utilisateur $u) => null !== $u->getPassword());
            if (!$actifs || !$this->acces->emailActif()) {
                $resultat['sans_compte'][] = $joueur;
            }
            foreach ($actifs as $compte) {
                $prevenus[$compte->getId()] = $compte;
            }
        }
        $this->em->persist($message);
        $this->em->flush();

        if ($parEmail && $this->acces->emailActif()) {
            foreach ($prevenus as $compte) {
                $this->envoyerEmail($message, $compte, \sprintf('%s : %s%s', $message->getClub()->getNom(), $message->isImportant() ? 'IMPORTANT – ' : '', $message->getSujet()), [
                    'titre' => $message->getSujet(),
                    'paragraphes' => preg_split('/\R{2,}/', (string) $message->getCorps()),
                    'details' => ['De' => $message->getAuteurNom(), 'Pour' => $message->getCibleLibelle()],
                    'bouton' => ['libelle' => 'Lire et répondre', 'url' => $this->lien($message, $compte)],
                    'note' => 'Pour répondre, utilisez le bouton ci-dessus : votre réponse arrive directement à ' . ($compte->isFamille() ? 'l\'encadrement.' : $message->getAuteurNom() . '.'),
                ], $resultat);
            }
        }

        return $resultat;
    }

    /** Une réponse vient d'être écrite : on prévient l'autre côté du fil par e-mail. */
    public function avertir(MessageReponse $reponse): void
    {
        if (!$this->acces->emailActif()) {
            return;
        }
        $message = $reponse->getMessage();
        $club = $message->getClub()->getNom();
        $sansSuite = ['emails' => 0, 'sans_compte' => [], 'echecs' => []];
        if ($reponse->isDeLEncadrement()) {
            $this->envoyerEmail($message, $reponse->getFamille(), \sprintf('%s : réponse à « %s »', $club, $message->getSujet()), [
                'titre' => 'Réponse de ' . $reponse->getAuteurNom(),
                'paragraphes' => preg_split('/\R{2,}/', $reponse->getCorps()),
                'bouton' => ['libelle' => 'Voir la conversation', 'url' => $this->lien($message, $reponse->getFamille())],
            ], $sansSuite);
        } elseif ($auteur = $message->getAuteur()) {
            $this->envoyerEmail($message, $auteur, \sprintf('%s : %s a répondu à « %s »', $club, $reponse->getAuteurNom(), $message->getSujet()), [
                'titre' => 'Réponse de ' . $reponse->getAuteurNom(),
                'paragraphes' => preg_split('/\R{2,}/', $reponse->getCorps()),
                'bouton' => ['libelle' => 'Voir et répondre', 'url' => $this->lien($message, $auteur) . '#fil-' . $reponse->getFamille()->getId()],
            ], $sansSuite);
        }
    }

    /**
     * Nom d'un compte famille pour l'encadrement : « Nicolas Descotes (joueur) » (compte du joueur lui-même)
     * ou « Parent de Léa, Tom ».
     */
    public function nomFamille(Utilisateur $compte): string
    {
        foreach ($compte->getJoueurs() as $j) {
            if ($this->acces->estLeJoueur($compte, $j)) {
                return $j->getPrenom() . ' ' . $j->getNom() . ' (joueur)';
            }
        }
        $prenoms = array_map(fn (Joueur $j) => $j->getPrenom(), $compte->getJoueurs()->toArray());

        return $prenoms ? 'Parent de ' . implode(', ', $prenoms) : (string) $compte->getEmail();
    }

    /**
     * Membres de l'encadrement à qui un compte famille peut écrire : ceux qui gèrent la catégorie de l'un de
     * ses joueurs (droit du secteur), et les administrateurs. Groupés pour l'affichage.
     *
     * @param list<Utilisateur> $encadrement comptes de l'encadrement du club
     *
     * @return array<string, list<Utilisateur>> libellé du groupe => comptes
     */
    public function interlocuteurs(Utilisateur $famille, array $encadrement): array
    {
        $groupes = [];
        // Corps médical (médecin, kiné...) : les familles peuvent leur écrire directement.
        $medicaux = array_filter($encadrement, fn (Utilisateur $u) => self::estMedical($u));
        $encadrement = array_values(array_filter($encadrement, fn (Utilisateur $u) => !self::estMedical($u)));
        foreach ($famille->getJoueurs() as $j) {
            $categorie = $j->getCategorie();
            $role = \App\Entity\Categorie::SECTEURS[$categorie?->getSecteur()]['role'] ?? null;
            if (!$categorie || !$role) {
                continue;
            }
            foreach ($encadrement as $u) {
                if (\in_array($role, $u->getRolesAttribues(), true) && !$u->isAdmin()) {
                    $groupes['Encadrement ' . $categorie->getNom()][$u->getId()] = $u;
                }
            }
        }
        foreach ($medicaux as $u) {
            $groupes[self::MEDICAL][$u->getId()] = $u;
        }
        foreach ($encadrement as $u) {
            if ($u->isAdmin()) {
                $groupes['Direction du club'][$u->getId()] = $u;
            }
        }

        return array_map('array_values', $groupes);
    }

    public const MEDICAL = 'Corps médical (médecin, kiné…)';

    /**
     * Médecin du club, ou compte qui n'a que des droits médicaux (kiné, infirmier...).
     * Un administrateur (qui a souvent tous les droits, « Médecin » compris) reste dans la direction.
     */
    public static function estMedical(Utilisateur $u): bool
    {
        return !$u->isAdmin() && ($u->isMedecin() || $u->isMedicalSeul());
    }

    /** Groupe d'un membre de l'encadrement dans la liste des destinataires. */
    public static function groupeEncadrement(Utilisateur $u): string
    {
        return match (true) {
            self::estMedical($u) => self::MEDICAL,
            $u->isAdmin() => 'Direction du club',
            [] !== array_intersect(array_column(\App\Entity\Categorie::SECTEURS, 'role'), $u->getRolesAttribues()) => 'Encadrement des équipes',
            default => 'Autres membres de l\'encadrement',
        };
    }

    /** Lien du message pour son destinataire : espace famille, ou page « Messages » de l'encadrement. */
    private function lien(Message $message, Utilisateur $destinataire): string
    {
        return $this->urls->generate($destinataire->isFamille() ? 'app_espace_message' : 'app_message_voir', ['id' => $message->getId()], UrlGeneratorInterface::ABSOLUTE_URL);
    }

    /**
     * Texte à copier dans WhatsApp ou un SMS, pour les familles sans compte actif.
     */
    public function texteACopier(Message $message): string
    {
        return implode("\n", [
            mb_strtoupper((string) $message->getClub()->getNom()) . ' – ' . ($message->isImportant() ? 'IMPORTANT – ' : '') . $message->getSujet(),
            '',
            (string) $message->getCorps(),
            '',
            '– ' . $message->getAuteurNom(),
        ]);
    }

    /**
     * @param array<string, mixed> $contenu
     * @param array{emails: int, sans_compte: list<Joueur>, echecs: list<string>} $resultat
     */
    private function envoyerEmail(Message $message, Utilisateur $a, string $sujet, array $contenu, array &$resultat): void
    {
        try {
            $this->mailer->send($this->courriel->message($message->getClub(), (string) $a->getEmail(), $sujet, $contenu));
            ++$resultat['emails'];
        } catch (TransportExceptionInterface $e) {
            $resultat['echecs'][] = (string) $a->getEmail();
            $this->logger->error('Message non envoyé à {email} : {erreur}', ['email' => $a->getEmail(), 'erreur' => $e->getMessage()]);
        }
    }
}
