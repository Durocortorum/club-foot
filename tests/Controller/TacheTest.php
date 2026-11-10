<?php

namespace App\Tests\Controller;

use App\Entity\Categorie;
use App\Entity\Club;
use App\Entity\Tache;
use App\Entity\Utilisateur;
use App\Service\CreationClub;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Tâches : confier une tâche (e-mail à la personne), « Mes tâches » à l'accueil et dans le menu,
 * statut par la personne chargée (e-mail à celle qui l'a confiée), droits de modification.
 */
class TacheTest extends WebTestCase
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
            foreach (['tache', 'categorie', 'utilisateur'] as $table) {
                $connexion->executeStatement("DELETE FROM $table WHERE club_id = ?", [$this->clubId]);
            }
            $connexion->executeStatement('DELETE FROM club WHERE id = ?', [$this->clubId]);
        }
        parent::tearDown();
    }

    public function testTaches(): void
    {
        $id = bin2hex(random_bytes(4));
        $admin = (new Utilisateur())->setEmail("admin-$id@exemple.test")->setNom('Test')->setPrenom('Admin');
        $club = (new Club())->setNom("Club Test $id");
        static::getContainer()->get(CreationClub::class)->creer($club, $admin, 'motdepasse-admin');
        $this->clubId = $club->getId();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $coach = (new Utilisateur())->setClub($club)->setEmail("coach-$id@exemple.test")->setNom('Durand')->setPrenom('Paul')->setRolesAttribues(['ROLE_FORMATION'])->setPassword('x');
        $autre = (new Utilisateur())->setClub($club)->setEmail("autre-$id@exemple.test")->setNom('Martin')->setPrenom('Léa')->setRolesAttribues(['ROLE_ECOLE_FOOT'])->setPassword('x');
        $u13 = $em->getRepository(Categorie::class)->findOneBy(['club' => $club, 'nom' => 'U13'])
            ?? (new Categorie())->setClub($club)->setNom('U13')->setSecteur('preformation');
        foreach ([$coach, $autre, $u13] as $e) {
            $em->persist($e);
        }
        $em->flush();

        // L'administrateur confie une tâche U13 à Paul : Paul reçoit un e-mail.
        $this->client->loginUser($admin);
        $crawler = $this->client->request('GET', '/taches');
        $crawler = $this->client->click($crawler->selectLink('+ Confier une tâche')->link());
        $this->client->submit($crawler->selectButton('Confier la tâche')->form([
            'tache[objet]' => 'Commander les maillots',
            'tache[responsable]' => $coach->getId(),
            'tache[categorie]' => $u13->getId(),
            'tache[echeance]' => (new \DateTimeImmutable('-1 day'))->format('Y-m-d'),
            'tache[urgente]' => '1',
        ]));
        self::assertEmailCount(1);
        $email = self::getMailerMessage();
        self::assertEmailAddressContains($email, 'To', "coach-$id@exemple.test");
        self::assertEmailHtmlBodyContains($email, 'Commander les maillots');
        $crawler = $this->client->followRedirect();
        self::assertStringContainsString('Paul est prévenu(e) par e-mail', $crawler->filter('.flash-succes, .flash')->text());
        $tache = $em->getRepository(Tache::class)->findOneBy(['club' => $this->clubId]);
        self::assertSame('U13', $tache->getCategorie()->getNom());
        self::assertTrue($tache->isEnRetard());

        // Paul : pastille du menu, tâche à l'accueil (urgente, en retard), il la marque faite → e-mail à l'admin.
        $this->client->loginUser($coach);
        $crawler = $this->client->request('GET', '/');
        self::assertSame('1', $crawler->filter('a[href="/taches"] .pastille')->text());
        $encart = $crawler->filter('.mes-taches');
        self::assertStringContainsString('Commander les maillots', $encart->text());
        self::assertStringContainsString('en retard', $encart->text());
        $this->client->submit($encart->selectButton('✓ Faite')->form());
        self::assertEmailCount(1);
        self::assertEmailAddressContains(self::getMailerMessage(), 'To', "admin-$id@exemple.test");
        $crawler = $this->client->followRedirect();
        self::assertCount(0, $crawler->filter('.mes-taches'));
        $em->clear();
        $tache = $em->find(Tache::class, $tache->getId());
        self::assertTrue($tache->isFaite());
        self::assertNotNull($tache->getFaiteLe());

        // Paul se donne une tâche : pas d'e-mail. Elle apparaît dans « Mes tâches ».
        $crawler = $this->client->request('GET', '/taches/nouvelle');
        $this->client->submit($crawler->selectButton('Confier la tâche')->form([
            'tache[objet]' => 'Préparer le plateau',
            'tache[responsable]' => $coach->getId(),
        ]));
        self::assertEmailCount(0);
        $crawler = $this->client->request('GET', '/taches');
        self::assertCount(1, $crawler->filter('tbody tr'));
        self::assertStringContainsString('Tout le club', $crawler->filter('tbody')->text());
        $crawler = $this->client->request('GET', '/taches?statut=faites');
        self::assertStringContainsString('Commander les maillots', $crawler->filter('tbody')->text());

        // Léa voit toutes les tâches mais ne peut pas modifier celle de Paul.
        $propre = $em->getRepository(Tache::class)->findOneBy(['club' => $this->clubId, 'objet' => 'Préparer le plateau']);
        $this->client->loginUser($autre);
        $crawler = $this->client->request('GET', '/taches?vue=toutes');
        self::assertCount(1, $crawler->filter('tbody tr'), 'tâches non faites');
        $crawler = $this->client->request('GET', '/taches/' . $propre->getId());
        self::assertCount(0, $crawler->selectLink('Modifier'));
        $this->client->request('GET', '/taches/' . $propre->getId() . '/modifier');
        self::assertResponseStatusCodeSame(403);
    }
}
