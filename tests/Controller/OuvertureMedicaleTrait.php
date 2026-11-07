<?php

namespace App\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\Mime\Email;

/** Ouvre l'accès aux données médicales comme un utilisateur : code demandé, reçu par e-mail, saisi. */
trait OuvertureMedicaleTrait
{
    private function ouvrirMedical(KernelBrowser $client): void
    {
        $crawler = $client->request('GET', '/medical/code');
        $client->submit($crawler->selectButton('Recevoir un code par e-mail')->form());
        $code = $this->codeRecu();
        $crawler = $client->followRedirect();
        $client->submit($crawler->selectButton('Ouvrir l\'accès')->form(), ['code' => $code]);
    }

    /** Code à 6 chiffres du dernier e-mail « Code d'accès aux données médicales ». */
    private function codeRecu(): string
    {
        $messages = array_values(array_filter(self::getMailerMessages(), fn ($m) => $m instanceof Email && str_contains((string) $m->getSubject(), 'données médicales')));
        self::assertNotEmpty($messages, 'aucun e-mail de code médical');
        preg_match('/\b(\d{6})\b/', end($messages)->getTextBody(), $m);

        return $m[1];
    }
}
