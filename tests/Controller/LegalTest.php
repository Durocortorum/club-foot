<?php

namespace App\Tests\Controller;

use App\Controller\LegalController;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/** Pages légales : publiques, reliées entre elles et depuis le pied de chaque page. */
class LegalTest extends WebTestCase
{
    public function testPagesPubliques(): void
    {
        $client = static::createClient();
        foreach (LegalController::PAGES as [$url, $titre]) {
            $crawler = $client->request('GET', $url);
            self::assertResponseIsSuccessful($url);
            self::assertSelectorTextContains('h1', $titre);
            self::assertCount(\count(LegalController::PAGES), $crawler->filter('footer.pied a'));
        }
        // CGV : l'accord de traitement des données (article 28 du RGPD) en annexe, les prix des formules.
        $crawler = $client->request('GET', '/conditions-vente');
        self::assertCount(1, $crawler->filter('#sous-traitance'));
        $prix = $crawler->filter('.page-legale table')->text();
        self::assertStringContainsString('Essentiel', $prix);
        self::assertStringContainsString('Formation', $prix);
        self::assertStringContainsString('650 €', $prix, 'Formation à la saison : 65 € × 10 mois');
        // Lien depuis la page de connexion.
        $crawler = $client->request('GET', '/connexion');
        self::assertCount(1, $crawler->filter('footer.pied a[href="/confidentialite"]'));
    }
}
