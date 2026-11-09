<?php

namespace App\Tests\Controller;

use App\Entity\Administratif;
use App\Entity\Benevole;
use App\Entity\Club;
use App\Entity\Utilisateur;
use App\Service\CreationClub;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Fiches de l'encadrement : administratifs et bénévoles, comme les éducateurs et dirigeants.
 */
class IntervenantTest extends WebTestCase
{
    private KernelBrowser $client;
    private ?int $clubId = null;

    protected function setUp(): void
    {
        $this->client = static::createClient();
    }

    protected function tearDown(): void
    {
        if ($this->clubId) {
            static::getContainer()->get(EntityManagerInterface::class)->getConnection()->executeStatement('DELETE FROM club WHERE id = ?', [$this->clubId]);
        }
        parent::tearDown();
    }

    public function testAdministratifsEtBenevoles(): void
    {
        $id = bin2hex(random_bytes(4));
        $admin = (new Utilisateur())->setEmail("admin-$id@exemple.test")->setNom('Test')->setPrenom('Coach');
        $club = (new Club())->setNom("Club Test $id");
        static::getContainer()->get(CreationClub::class)->creer($club, $admin, 'motdepasse-admin');
        $this->clubId = $club->getId();
        $this->client->loginUser($admin);

        // Onglet Bénévoles de l'encadrement : création d'une fiche.
        $crawler = $this->client->request('GET', '/encadrement/educateurs');
        $crawler = $this->client->click($crawler->selectLink('Bénévoles')->link());
        self::assertSelectorTextContains('h1', 'Bénévoles');
        $crawler = $this->client->click($crawler->selectLink('+ Nouveau bénévole')->link());
        self::assertSelectorTextContains('h1', 'Nouveau bénévole');
        $this->client->submit($crawler->selectButton('Enregistrer')->form([
            'intervenant[nom]' => 'Martin', 'intervenant[prenom]' => 'Sophie', 'intervenant[fonction]' => 'Buvette',
        ]));
        $this->client->followRedirect();
        self::assertSelectorTextContains('main', 'Bénévole');
        self::assertSelectorTextContains('main', 'MARTIN');

        // Onglet Administratifs : création, puis départ du club → visible dans les archives.
        $crawler = $this->client->request('GET', '/encadrement/administratifs');
        $crawler = $this->client->click($crawler->selectLink('+ Nouvel administratif')->link());
        $this->client->submit($crawler->selectButton('Enregistrer')->form([
            'intervenant[nom]' => 'Durand', 'intervenant[prenom]' => 'Paul', 'intervenant[fonction]' => 'Secrétariat', 'intervenant[ancien]' => true,
        ]));
        $crawler = $this->client->request('GET', '/archives');
        $crawler = $this->client->click($crawler->filter('a.tuile:contains("Administratifs")')->link());
        self::assertSelectorTextContains('.grille-personnes', 'Paul DURAND');

        // Arbitre : niveau et équipement en plus de la fiche commune.
        $crawler = $this->client->request('GET', '/encadrement/arbitres');
        $crawler = $this->client->click($crawler->selectLink('+ Nouvel arbitre')->link());
        $this->client->submit($crawler->selectButton('Enregistrer')->form([
            'intervenant[nom]' => 'Bernard', 'intervenant[prenom]' => 'Luc', 'intervenant[niveau]' => 'District', 'intervenant[equipement]' => '2 tenues, sifflet',
        ]));
        $this->client->followRedirect();
        self::assertSelectorTextContains('main', 'District');
        self::assertSelectorTextContains('main', '2 tenues, sifflet');
        $this->client->request('GET', '/encadrement/arbitres');
        self::assertSelectorTextContains('.grille-personnes', 'District');

        $em = static::getContainer()->get(EntityManagerInterface::class);
        self::assertSame('Buvette', $em->getRepository(Benevole::class)->findOneBy(['club' => $this->clubId])?->getFonction());
        self::assertTrue($em->getRepository(Administratif::class)->findOneBy(['club' => $this->clubId])?->isAncien());
    }
}
