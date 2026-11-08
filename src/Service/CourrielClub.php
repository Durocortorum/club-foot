<?php

namespace App\Service;

use App\Entity\Club;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mime\Address;

/**
 * Habillage commun des e-mails du club : en-tête aux couleurs du club avec son logo (joint à l'e-mail,
 * pour s'afficher même quand les images distantes sont bloquées), pied de page avec ses coordonnées,
 * expéditeur au nom du club et adresse de réponse = e-mail de contact du club.
 *
 * message() construit un e-mail simple (titre, paragraphes, encadré, détails, bouton) avec les gabarits
 * emails/message.*.twig ; habiller() s'applique aux e-mails qui ont leur propre gabarit (invitation, convocation).
 */
class CourrielClub
{
    public function __construct(
        #[Autowire('%env(MAILER_EXPEDITEUR)%')]
        private readonly string $expediteur,
        #[Autowire('%kernel.project_dir%/var/club')]
        private readonly string $dossierLogos,
    ) {
    }

    /**
     * @param array{
     *     titre: string,
     *     salutation?: ?string,
     *     paragraphes?: list<string>,
     *     encadre?: ?string,
     *     details?: array<string, ?string>,
     *     bouton?: ?array{libelle: string, url: string},
     *     note?: ?string,
     * } $contenu
     */
    public function message(Club $club, string|Address $a, string $sujet, array $contenu): TemplatedEmail
    {
        $contenu += ['salutation' => 'Bonjour,', 'paragraphes' => [], 'encadre' => null, 'details' => [], 'bouton' => null, 'note' => null];
        $contenu['details'] = array_filter($contenu['details'], fn ($v) => null !== $v && '' !== $v);

        return $this->habiller((new TemplatedEmail())
            ->to($a)
            ->subject($sujet)
            ->htmlTemplate('emails/message.html.twig')
            ->textTemplate('emails/message.txt.twig')
            ->context(['contenu' => $contenu]), $club);
    }

    /** Expéditeur, adresse de réponse, logo joint et variables du gabarit commun (club, logo). */
    public function habiller(TemplatedEmail $email, Club $club): TemplatedEmail
    {
        $email->from(new Address($this->expediteur, $club->getNom()));
        if ($club->getEmailContact()) {
            $email->replyTo(new Address($club->getEmailContact(), $club->getNom()));
        }
        $logo = $club->getLogo() ? $this->dossierLogos . '/' . $club->getLogo() : null;
        $avecLogo = $logo && is_file($logo);
        if ($avecLogo) {
            $email->embedFromPath($logo, 'logo-club');
        }

        return $email->context($email->getContext() + [
            'club' => $club,
            'logo_cid' => $avecLogo ? 'cid:logo-club' : null,
            'initiales' => self::initiales($club->getNom()),
        ]);
    }

    /** « Stade de Reims » → « SR » : pastille de l'en-tête quand le club n'a pas de logo. */
    private static function initiales(string $nom): string
    {
        $mots = array_values(array_filter(preg_split('/[\s\-]+/u', $nom) ?: [], fn ($m) => mb_strlen($m) > 2 || ctype_upper(mb_substr($m, 0, 1))));
        $lettres = array_map(fn ($m) => mb_strtoupper(mb_substr($m, 0, 1)), \array_slice($mots ?: [$nom], 0, 2));

        return implode('', $lettres) ?: 'C';
    }
}
