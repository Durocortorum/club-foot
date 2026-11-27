<?php

namespace App\Service;

use App\Entity\Convocation;
use App\Entity\Convoque;
use App\Entity\Entrainement;
use App\Entity\Joueur;
use App\Repository\JoueurRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Prévient les familles d'une convocation : un e-mail à chaque compte famille actif des joueurs convoqués.
 * Les joueurs sans compte actif sont signalés, pour que l'éducateur les prévienne autrement (WhatsApp, SMS).
 */
class EnvoiConvocations
{
    public function __construct(
        private readonly AccesFamille $acces,
        private readonly MailerInterface $mailer,
        private readonly UrlGeneratorInterface $urls,
        private readonly EntityManagerInterface $em,
        private readonly LoggerInterface $logger,
        private readonly JoueurRepository $joueurs,
        #[Autowire('%env(MAILER_EXPEDITEUR)%')]
        private readonly string $expediteur,
        private readonly CourrielClub $courriel,
        private readonly NotificationsTelephone $notifications,
    ) {
    }

    /**
     * @param bool $relance  seulement les joueurs qui n'ont pas encore répondu
     * @param bool $nouveaux   seulement les joueurs dont la famille n'a pas encore été prévenue (ajoutés après l'envoi)
     * @param bool $definitive convocation définitive : tous les convoqués sauf les absents
     *
     * @return array{emails: int, joueurs: int, sans_compte: list<Joueur>, echecs: list<string>}
     */
    public function envoyer(Convocation $convocation, bool $relance = false, bool $nouveaux = false, bool $definitive = false): array
    {
        $resultat = ['emails' => 0, 'joueurs' => 0, 'sans_compte' => [], 'echecs' => []];
        $club = $convocation->getRencontre()->getClub();
        $espace = $this->urls->generate('app_espace', [], UrlGeneratorInterface::ABSOLUTE_URL);

        foreach ($convocation->getConvoques() as $c) {
            if (($relance && null !== $c->getReponse()) || ($nouveaux && null !== $c->getEnvoyeLe()) || ($definitive && Convoque::ABSENT === $c->getReponse())) {
                continue;
            }
            // Famille prévenue (par e-mail, ou à prévenir autrement si elle n'a pas de compte) : plus « nouveau ».
            $c->marquerEnvoye();
            ++$resultat['joueurs'];
            $comptes = array_filter($this->acces->comptes($c->getJoueur()), fn ($u) => null !== $u->getPassword());
            $r = $convocation->getRencontre();
            $this->notifications->envoyer($comptes,
                \sprintf('%s%s convoqué(e)', $definitive ? 'Convocation définitive : ' : ($relance ? 'Rappel : ' : ''), $c->getJoueur()->getPrenom()),
                \sprintf('%s – %s, %s %s%s. Répondez présent ou absent.', $r->getEquipeAffichee(), $r->getAdversaire(), self::JOURS[(int) $r->getDate()->format('w')], $r->getDate()->format('d/m'), $r->getHeure() ? ' à ' . $r->getHeure() : ''),
                $espace);
            if (!$comptes || !$this->acces->emailActif()) {
                $resultat['sans_compte'][] = $c->getJoueur();
                continue;
            }
            foreach ($comptes as $compte) {
                if (!$compte->recoitEmail('convocations')) {
                    continue; // e-mails coupés dans « Mon compte » : la convocation reste dans l'espace (et la notification part)
                }
                $message = $this->courriel->habiller((new TemplatedEmail())
                    ->to($compte->getEmail())
                    ->subject(\sprintf('%s%s : %s convoqué(e) le %s', $definitive ? 'Convocation définitive – ' : ($relance ? 'Rappel – ' : ''), $club->getNom(), $c->getJoueur()->getPrenom(), $convocation->getRencontre()->getDate()->format('d/m')))
                    ->htmlTemplate('emails/convocation.html.twig')
                    ->textTemplate('emails/convocation.txt.twig')
                    ->context(['convocation' => $convocation, 'convoque' => $c, 'espace' => $espace, 'relance' => $relance, 'definitive' => $definitive]), $club);
                try {
                    $this->mailer->send($message);
                    ++$resultat['emails'];
                } catch (TransportExceptionInterface $e) {
                    $resultat['echecs'][] = $compte->getEmail();
                    $this->logger->error('Convocation non envoyée à {email} : {erreur}', ['email' => $compte->getEmail(), 'erreur' => $e->getMessage()]);
                }
            }
        }
        if (!$relance) {
            $convocation->marquerEnvoyee();
        }
        $this->em->flush();

        return $resultat;
    }

    /**
     * Entraînement annulé : un e-mail à chaque compte famille actif des joueurs de la catégorie.
     *
     * @return int e-mails envoyés
     */
    public function annoncerAnnulation(Entrainement $entrainement): int
    {
        return $this->annoncer($entrainement, 'annulé', $this->messageAnnulation($entrainement));
    }

    /** Entraînement rétabli après une annulation : les familles sont prévenues qu'il a bien lieu. */
    public function annoncerRetablissement(Entrainement $entrainement): int
    {
        return $this->annoncer($entrainement, 'maintenu', $this->messageRetablissement($entrainement));
    }

    public function messageRetablissement(Entrainement $e): string
    {
        return \sprintf("ENTRAÎNEMENT MAINTENU – %s\nFinalement, l'entraînement du %s a bien lieu : %s%s.",
            $e->getCategorie(), $this->quand($e), $e->getHoraire(), $e->getLieu() ? ', ' . $e->getLieu() : '');
    }

    /** Un e-mail à chaque compte famille actif des joueurs de la catégorie de l'entraînement. */
    private function annoncer(Entrainement $entrainement, string $etat, string $texte): int
    {
        $club = $entrainement->getClub();
        $envoyes = 0;
        $deja = [];
        foreach ($this->joueurs->findBy(['categorie' => $entrainement->getCategorie(), 'ancien' => false]) as $j) {
            foreach ($this->acces->comptes($j) as $compte) {
                if (null !== $compte->getPassword()) {
                    $deja[$compte->getId()] ??= $compte;
                }
            }
        }
        // Notification sur le téléphone : première ligne en titre, la suite en texte.
        $lignes = explode("\n", $texte);
        $this->notifications->envoyer($deja, \sprintf('Entraînement %s – %s', $etat, $entrainement->getCategorie()), implode(' ', \array_slice($lignes, 1)),
            $this->urls->generate('app_espace', [], UrlGeneratorInterface::ABSOLUTE_URL));
        if (!$this->acces->emailActif()) {
            return 0;
        }
        foreach ($deja as $compte) {
            if (!$compte->recoitEmail('entrainements')) {
                continue;
            }
            try {
                // Première ligne du message (« ENTRAÎNEMENT ANNULÉ – U13 ») : titre ; la suite : le détail.
                $this->mailer->send($this->courriel->message($club, (string) $compte->getEmail(),
                    \sprintf('%s : entraînement %s %s', $club->getNom(), $this->quand($entrainement), $etat), [
                        'titre' => \sprintf('Entraînement %s – %s', $etat, $entrainement->getCategorie()),
                        'paragraphes' => \array_slice($lignes, 1),
                        'bouton' => ['libelle' => 'Voir mon espace', 'url' => $this->urls->generate('app_espace', [], UrlGeneratorInterface::ABSOLUTE_URL)],
                    ]));
                ++$envoyes;
            } catch (TransportExceptionInterface $e) {
                $this->logger->error('Annulation non envoyée à {email} : {erreur}', ['email' => $compte->getEmail(), 'erreur' => $e->getMessage()]);
            }
        }

        return $envoyes;
    }

    /** Message d'annulation, pour l'e-mail et le groupe WhatsApp. */
    public function messageAnnulation(Entrainement $e): string
    {
        return \sprintf("ENTRAÎNEMENT ANNULÉ – %s\n%s %s%s.%s",
            $e->getCategorie(), ucfirst($this->quand($e)), $e->getHoraire(), $e->getLieu() ? ', ' . $e->getLieu() : '',
            $e->getMotifAnnulation() ? "\nMotif : " . $e->getMotifAnnulation() : '');
    }

    private function quand(Entrainement $e): string
    {
        return mb_strtolower(self::JOURS[(int) $e->getDate()->format('w')]) . ' ' . $e->getDate()->format('d/m');
    }

    /** Message prêt à coller dans le groupe WhatsApp de l'équipe. */
    public function messageGroupe(Convocation $convocation): string
    {
        $r = $convocation->getRencontre();
        // Pas d'émoji : certaines versions de WhatsApp les affichent en « � » dans un message prérempli.
        $lignes = [
            \sprintf('CONVOCATION %s – %s', $r->getEquipeAffichee(), $r->getAdversaire()),
            \sprintf('%s : %s %s%s%s', $r->isPlateau() ? 'Plateau' : 'Match', self::JOURS[(int) $r->getDate()->format('w')], $r->getDate()->format('d/m'), $r->getHeure() ? ' à ' . $r->getHeure() : '', $r->getLieu() ? ', ' . $r->getLieu() : ''),
        ];
        if ($convocation->getRdvHeure() || $convocation->getRdvLieu()) {
            $lignes[] = 'Rendez-vous : ' . trim(($convocation->getRdvHeure() ? $convocation->getRdvHeure() : '') . ($convocation->getRdvLieu() ? ', ' . $convocation->getRdvLieu() : ''), ' ,');
        }
        foreach ($r->getDeplacements() as $d) {
            $lignes[] = 'Déplacement : ' . $d->getResumeFamilles();
        }
        if ($convocation->getMessage()) {
            $lignes[] = $convocation->getMessage();
        }
        $lignes[] = '';
        $lignes[] = 'Joueurs convoqués :';
        foreach ($convocation->getConvoques() as $c) {
            $lignes[] = '- ' . $c->getJoueur()->getNomComplet();
        }
        $lignes[] = '';
        $lignes[] = 'Merci de répondre présent ou absent dans l\'espace famille : ' . $this->urls->generate('app_espace', [], UrlGeneratorInterface::ABSOLUTE_URL);

        return implode("\n", $lignes);
    }

    /**
     * SMS à envoyer depuis le téléphone de l'éducateur : numéros connus du joueur et texte prêt.
     * Mineur : parents d'abord ; majeur : son propre portable d'abord.
     *
     * @return array{numeros: array<string, string>, texte: string, compte: bool} numéros : libellé => numéro
     */
    public function sms(Convoque $c): array
    {
        $j = $c->getJoueur();
        $candidats = ['Parent 1' => $j->getTelPortable1(), 'Parent 2' => $j->getTelPortable2()];
        $candidats = $j->isMineur() ? $candidats + ['Joueur' => $j->getTelJoueur()] : ['Joueur' => $j->getTelJoueur()] + $candidats;
        $numeros = [];
        foreach ($candidats as $qui => $tel) {
            $tel = preg_replace('/[^\d+]/', '', (string) $tel);
            if (\strlen($tel) >= 10 && !\in_array($tel, $numeros, true)) {
                $numeros[$qui] = $tel;
            }
        }

        $compte = (bool) array_filter($this->acces->comptes($j), fn ($u) => null !== $u->getPassword());
        $cv = $c->getConvocation();
        $r = $cv->getRencontre();
        $texte = \sprintf('%s : %s est convoqué(e) pour %s – %s, %s %s%s.',
            $r->getClub()->getNom(), $j->getPrenom(), $r->getEquipeAffichee(), $r->getAdversaire(),
            mb_strtolower(self::JOURS[(int) $r->getDate()->format('w')]), $r->getDate()->format('d/m'), $r->getHeure() ? ' à ' . $r->getHeure() : '');
        if ($cv->getRdvHeure() || $cv->getRdvLieu()) {
            $texte .= ' RDV' . ($cv->getRdvHeure() ? ' ' . $cv->getRdvHeure() : '') . ($cv->getRdvLieu() ? ', ' . $cv->getRdvLieu() : '') . '.';
        }
        if ($cv->getMessage()) {
            $texte .= ' ' . preg_replace('/\s+/', ' ', trim($cv->getMessage()));
        }
        $texte .= $compte
            ? ' Merci de répondre dans votre espace famille : ' . $this->urls->generate('app_espace', [], UrlGeneratorInterface::ABSOLUTE_URL)
            : ' Merci de répondre par SMS : présent ou absent.';

        return ['numeros' => $numeros, 'texte' => $texte, 'compte' => $compte];
    }

    public const JOURS = ['Dimanche', 'Lundi', 'Mardi', 'Mercredi', 'Jeudi', 'Vendredi', 'Samedi'];

    /**
     * Objet et texte de la convocation déposée dans la messagerie interne des familles (un même message pour
     * toutes : chacune voit « pour Léa » d'après ses propres joueurs).
     *
     * @return array{sujet: string, corps: string, cible: string}
     */
    public function pourMessagerie(Convocation $convocation, bool $relance, bool $definitive = false): array
    {
        $r = $convocation->getRencontre();
        $quand = \sprintf('%s %s%s', self::JOURS[(int) $r->getDate()->format('w')], $r->getDate()->format('d/m'), $r->getHeure() ? ' à ' . $r->getHeure() : '');
        $lignes = [
            \sprintf('%s : %s, %s%s.', $r->isPlateau() ? 'Plateau' : 'Match', $r->getIntitule(), mb_strtolower($quand), $r->getLieu() ? ', ' . $r->getLieu() : ''),
        ];
        if ($convocation->getRdvHeure() || $convocation->getRdvLieu()) {
            $lignes[] = 'Rendez-vous : ' . trim(($convocation->getRdvHeure() ?? '') . ($convocation->getRdvLieu() ? ', ' . $convocation->getRdvLieu() : ''), ' ,') . '.';
        }
        if ($convocation->getMessage()) {
            $lignes[] = '';
            $lignes[] = $convocation->getMessage();
        }
        $lignes[] = '';
        $lignes[] = match (true) {
            $definitive => 'Convocation définitive : le joueur fait partie du groupe retenu pour ce ' . ($r->isPlateau() ? 'plateau' : 'match') . '. En cas d\'empêchement, prévenez-nous au plus vite (réponse « absent » dans « Mon espace »).',
            $relance => 'Nous n\'avons pas encore votre réponse : merci d\'indiquer présent ou absent dans « Mon espace ».',
            default => 'Merci de répondre présent ou absent dans « Mon espace ».',
        };

        return [
            'sujet' => mb_substr(\sprintf('%sConvocation %s, %s', $definitive ? 'Définitive – ' : ($relance ? 'Rappel – ' : ''), $r->getAfficheContre(), mb_strtolower($quand)), 0, 120),
            'corps' => implode("\n", $lignes),
            'cible' => \sprintf('%s – %s, %s', $relance ? 'Convoqués sans réponse' : 'Convoqués', $r->getAfficheContre(), $r->getDate()->format('d/m')),
        ];
    }

    /** Libellé d'une réponse, pour l'affichage. */
    public static function libelle(?string $reponse): string
    {
        return match ($reponse) {
            Convoque::PRESENT => 'Présent',
            Convoque::ABSENT => 'Absent',
            default => 'Sans réponse',
        };
    }
}
