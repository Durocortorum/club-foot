<?php

namespace App\Tests\Controller;

use App\Entity\Categorie;
use App\Entity\Club;
use App\Entity\Rencontre;
use App\Entity\Utilisateur;
use App\Service\CreationClub;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Plateaux de l'école de foot : équipe « à plateaux », plateau créé chez un autre club, petits matchs notés
 * pour mémoire (sans score d'ensemble, hors bilan). Données fictives, club supprimé à la fin.
 */
class PlateauTest extends WebTestCase
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
            $connexion = static::getContainer()->get(EntityManagerInterface::class)->getConnection();
            $connexion->executeStatement('DELETE FROM rencontre WHERE club_id = ?', [$this->clubId]);
            $connexion->executeStatement('DELETE FROM club WHERE id = ?', [$this->clubId]);
        }
        parent::tearDown();
    }

    public function testPlateauChezUnAutreClub(): void
    {
        $id = bin2hex(random_bytes(4));
        $admin = (new Utilisateur())->setEmail("admin-$id@exemple.test")->setNom('Test')->setPrenom('Coach');
        $club = (new Club())->setNom("Club Test $id");
        static::getContainer()->get(CreationClub::class)->creer($club, $admin, 'motdepasse-admin');
        $this->clubId = $club->getId();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $equipes = [];
        foreach ($em->getRepository(Categorie::class)->findBy(['club' => $club]) as $c) {
            $equipes[$c->getNom()] = $c;
        }
        // École de foot à plateaux d'office, les autres équipes jouent des matchs.
        self::assertTrue($equipes['U9']->isPlateaux());
        self::assertFalse($equipes['U13']->isPlateaux());

        $this->client->loginUser($admin);
        $crawler = $this->client->request('GET', '/matchs?categorie=' . $equipes['U9']->getId());
        self::assertSelectorTextContains('h1', 'Plateaux');
        self::assertSelectorNotExists('a:contains("+ Nouveau match")');

        // Nouveau plateau chez un autre club : pas d'équipe à matchs proposée.
        $crawler = $this->client->click($crawler->selectLink('+ Nouveau plateau')->link());
        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('select[name="plateau[categorie]"] option:contains("U13")');
        $form = $crawler->selectButton('Enregistrer sans convoquer')->form([
            'plateau[date]' => (new \DateTimeImmutable('-2 days'))->format('Y-m-d'),
            'plateau[heure]' => '10h00',
            'plateau[categorie]' => (string) $equipes['U9']->getId(),
            'plateau[chez]' => 'autre',
            'plateau[organisateur]' => 'us vallée verte',
            'plateau[lieu]' => 'Stade de la Vallée',
            'plateau[clubs]' => 'FC Bords de Loire, SC Les Moulins',
        ]);
        $this->client->submit($form);
        $plateau = $em->getRepository(Rencontre::class)->findOneBy(['club' => $club]);
        self::assertResponseRedirects('/matchs/' . $plateau->getId());
        self::assertTrue($plateau->isPlateau());
        self::assertSame('US VALLÉE VERTE', $plateau->getOrganisateur());
        self::assertSame(['FC BORDS DE LOIRE', 'SC LES MOULINS'], $plateau->getClubsPlateau());

        // Petits matchs notés après le plateau : pour mémoire, sans score d'ensemble.
        $crawler = $this->client->request('GET', '/matchs/' . $plateau->getId() . '/modifier');
        self::assertResponseIsSuccessful();
        $valeurs = $crawler->selectButton('Enregistrer le plateau')->form()->getPhpValues();
        $valeurs['plateau']['matchsPlateau'] = [
            ['adversaire' => 'US Vallée Verte', 'pour' => '2', 'contre' => '1'],
            ['adversaire' => 'FC Bords de Loire', 'pour' => '0', 'contre' => '0'],
            ['adversaire' => '', 'pour' => '', 'contre' => ''],
        ];
        $this->client->request('POST', '/matchs/' . $plateau->getId() . '/modifier', $valeurs);
        self::assertResponseRedirects('/matchs/' . $plateau->getId());
        $em->clear();
        $plateau = $em->getRepository(Rencontre::class)->find($plateau->getId());
        self::assertCount(2, $plateau->getMatchsPlateau(), 'la ligne sans adversaire est ignorée');
        self::assertSame(['V' => 1, 'N' => 1, 'D' => 0], $plateau->getBilanPlateau());
        self::assertNull($plateau->getResultat(), 'un plateau ne compte pas dans le bilan');

        $this->client->request('GET', '/matchs/' . $plateau->getId());
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Plateau chez US VALLÉE VERTE');
        self::assertSelectorTextContains('.petit-match', 'US VALLÉE VERTE');
        self::assertSelectorNotExists('a:contains("Feuille de match")');
    }
}
