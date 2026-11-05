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
    ) {
    }

    /**
     * @param bool $relance seulement les joueurs qui n'ont pas encore répondu
     *
     * @return array{emails: int, sans_compte: list<Joueur>, echecs: list<string>}
     */
    public function envoyer(Convocation $convocation, bool $relance = false): array
    {
        $resultat = ['emails' => 0, 'sans_compte' => [], 'echecs' => []];
        $club = $convocation->getRencontre()->getClub();
        $espace = $this->urls->generate('app_espace', [], UrlGeneratorInterface::ABSOLUTE_URL);

        foreach ($convocation->getConvoques() as $c) {
            if ($relance && null !== $c->getReponse()) {
                continue;
            }
            $comptes = array_filter($this->acces->comptes($c->getJoueur()), fn ($u) => null !== $u->getPassword());
            if (!$comptes || !$this->acces->emailActif()) {
                $resultat['sans_compte'][] = $c->getJoueur();
                continue;
            }
            foreach ($comptes as $compte) {
                $message = (new TemplatedEmail())
                    ->from(new Address($this->expediteur, $club->getNom()))
                    ->to($compte->getEmail())
                    ->subject(\sprintf('%s%s : %s convoqué(e) le %s', $relance ? 'Rappel – ' : '', $club->getNom(), $c->getJoueur()->getPrenom(), $convocation->getRencontre()->getDate()->format('d/m')))
                    ->htmlTemplate('emails/convocation.html.twig')
                    ->textTemplate('emails/convocation.txt.twig')
                    ->context(['club' => $club, 'convocation' => $convocation, 'convoque' => $c, 'espace' => $espace, 'relance' => $relance]);
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
        if (!$this->acces->emailActif()) {
            return 0;
        }
        $club = $entrainement->getClub();
        $envoyes = 0;
        $deja = [];
        foreach ($this->joueurs->findBy(['categorie' => $entrainement->getCategorie(), 'ancien' => false]) as $j) {
            foreach ($this->acces->comptes($j) as $compte) {
                if (null === $compte->getPassword() || isset($deja[$compte->getId()])) {
                    continue;
                }
                $deja[$compte->getId()] = true;
                try {
                    $this->mailer->send((new TemplatedEmail())
                        ->from(new Address($this->expediteur, $club->getNom()))
                        ->to($compte->getEmail())
                        ->subject(\sprintf('%s : entraînement %s annulé', $club->getNom(), $this->quand($entrainement)))
                        ->text($this->messageAnnulation($entrainement)));
                    ++$envoyes;
                } catch (TransportExceptionInterface $e) {
                    $this->logger->error('Annulation non envoyée à {email} : {erreur}', ['email' => $compte->getEmail(), 'erreur' => $e->getMessage()]);
                }
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
            \sprintf('Match : %s %s%s%s', self::JOURS[(int) $r->getDate()->format('w')], $r->getDate()->format('d/m'), $r->getHeure() ? ' à ' . $r->getHeure() : '', $r->getLieu() ? ', ' . $r->getLieu() : ''),
        ];
        if ($convocation->getRdvHeure() || $convocation->getRdvLieu()) {
            $lignes[] = 'Rendez-vous : ' . trim(($convocation->getRdvHeure() ? $convocation->getRdvHeure() : '') . ($convocation->getRdvLieu() ? ', ' . $convocation->getRdvLieu() : ''), ' ,');
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
