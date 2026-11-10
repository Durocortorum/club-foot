<?php

namespace App\Tests\Controller;

use App\Entity\Categorie;
use App\Entity\Club;
use App\Entity\Joueur;
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
            foreach (['tache', 'utilisateur', 'joueur', 'categorie'] as $table) {
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
        // Menu de l'administrateur : 1 tâche en cours dans le club, aucune pour lui (pastille grise, lien vers toutes).
        $pastille = $crawler->filter('a[href="/taches?vue=toutes"] .pastille');
        self::assertSame('1', $pastille->text());
        self::assertStringNotContainsString('alerte', $pastille->attr('class'));
        // Accueil de l'administrateur : la tâche de Paul dans « Tâches en cours du club », pas dans « Mes tâches ».
        $crawler = $this->client->request('GET', '/');
        self::assertCount(0, $crawler->filter('.mes-taches:not(.taches-club)'));
        self::assertStringContainsString('Commander les maillots', $crawler->filter('.taches-club')->text());
        self::assertStringContainsString('Paul DURAND', $crawler->filter('.taches-club')->text());
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
        self::assertCount(0, $crawler->filter('.mes-taches:not(.taches-club)'));
        $em->clear();
        $tache = $em->find(Tache::class, $tache->getId());
        self::assertTrue($tache->isFaite());
        self::assertNotNull($tache->getFaiteLe());
        // Paul est chargé de la tâche mais ne l'a pas confiée : il change le statut, pas le reste.
        $crawler = $this->client->request('GET', '/taches/' . $tache->getId());
        self::assertCount(1, $crawler->selectButton('Rouvrir'));
        self::assertCount(0, $crawler->selectLink('Modifier'));
        self::assertCount(0, $crawler->filter('form[action$="/supprimer"]'));
        $this->client->request('GET', '/taches/' . $tache->getId() . '/modifier');
        self::assertResponseStatusCodeSame(403);

        // Paul se donne une tâche : e-mail de rappel. Elle apparaît dans « Mes tâches ».
        $crawler = $this->client->request('GET', '/taches/nouvelle');
        $this->client->submit($crawler->selectButton('Confier la tâche')->form([
            'tache[objet]' => 'Préparer le plateau',
            'tache[responsable]' => $coach->getId(),
        ]));
        self::assertEmailCount(1);
        $rappel = self::getMailerMessage();
        self::assertEmailAddressContains($rappel, 'To', "coach-$id@exemple.test");
        self::assertEmailHtmlBodyContains($rappel, 'Vous avez noté cette tâche pour vous.');
        $crawler = $this->client->request('GET', '/taches');
        self::assertCount(1, $crawler->filter('tbody tr'));
        self::assertCount(1, $crawler->filter('tbody tr')->selectLink('Modifier'), 'bouton Modifier sur la ligne');
        self::assertCount(1, $crawler->filter('tbody tr form[action$="/supprimer"]'), 'bouton Supprimer sur la ligne');
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

        // Léa confie une tâche privée à Paul : eux deux la voient, ni l'administrateur ni les autres.
        $crawler = $this->client->request('GET', '/taches/nouvelle');
        $this->client->submit($crawler->selectButton('Confier la tâche')->form([
            'tache[objet]' => 'Entretien individuel',
            'tache[responsable]' => $coach->getId(),
            'tache[privee]' => '1',
        ]));
        $privee = $em->getRepository(Tache::class)->findOneBy(['club' => $this->clubId, 'objet' => 'Entretien individuel']);
        self::assertTrue($privee->isPrivee());
        $crawler = $this->client->request('GET', '/taches?vue=donnees');
        self::assertStringContainsString('privée', $crawler->filter('tbody')->text());
        $this->client->loginUser($em->find(Utilisateur::class, $coach->getId()));
        $crawler = $this->client->request('GET', '/taches');
        self::assertStringContainsString('Entretien individuel', $crawler->filter('tbody')->text());
        $this->client->loginUser($em->find(Utilisateur::class, $admin->getId()));
        $crawler = $this->client->request('GET', '/taches?vue=toutes&statut=toutes');
        self::assertStringNotContainsString('Entretien individuel', $crawler->filter('tbody')->text());
        self::assertSame('1', $crawler->filter('nav.onglets a')->last()->filter('.pastille')->text(), 'compteur sans la tâche privée');
        // Page restée ouverte ou adresse directe : retour à la liste avec un message, pas de page d'erreur.
        foreach (['', '/modifier'] as $page) {
            $this->client->request('GET', '/taches/' . $privee->getId() . $page);
            self::assertResponseRedirects('/taches');
            $this->client->followRedirect();
            self::assertSelectorTextContains('.flash-info', 'Cette tâche est privée');
            self::assertSelectorTextNotContains('main', 'Entretien individuel');
        }
        $idPrivee = $privee->getId();
        $em->remove($em->find(Tache::class, $idPrivee));
        $em->flush();
        // Tâche supprimée (ancienne page, lien d'un e-mail) : retour à la liste avec un message.
        foreach (['', '/modifier'] as $page) {
            $this->client->request('GET', '/taches/' . $idPrivee . $page);
            self::assertResponseRedirects('/taches');
            $this->client->followRedirect();
            self::assertSelectorTextContains('.flash-info', 'Cette tâche n\'existe plus');
        }

        // Tâche confiée à un joueur : la famille reçoit un e-mail, la voit dans son espace et la marque faite.
        $club = $em->find(Club::class, $this->clubId);
        $u13 = $em->find(Categorie::class, $u13->getId());
        $joueur = (new Joueur())->setClub($club)->setCategorie($u13)->setNom('Petit')->setPrenom('Tom');
        $parent = (new Utilisateur())->setClub($club)->setFamille(true)->setEmail("parent-$id@exemple.test")->setNom('Petit')->setPrenom('Famille')->setPassword('x')->addJoueur($joueur);
        $em->persist($joueur);
        $em->persist($parent);
        $em->flush();
        $this->client->loginUser($em->find(Utilisateur::class, $admin->getId()));
        $crawler = $this->client->request('GET', '/taches/nouvelle');
        // Chaque joueur porte sa catégorie : la page filtre la liste selon la catégorie choisie.
        self::assertSame((string) $u13->getId(), $crawler->filter('#tache_joueur option[value="' . $joueur->getId() . '"]')->attr('data-categorie'));
        self::assertSame('tache', $crawler->filter('form[name="tache"]')->attr('data-controller'));
        $this->client->submit($crawler->selectButton('Confier la tâche')->form(['tache[objet]' => 'Laver les maillots']));
        self::assertSelectorTextContains('main', "Choisissez une personne de l'encadrement ou un joueur.");
        $this->client->submit($crawler->selectButton('Confier la tâche')->form([
            'tache[objet]' => 'Laver les maillots',
            'tache[joueur]' => $joueur->getId(),
            'tache[categorie]' => $u13->getId(),
            'tache[echeance]' => (new \DateTimeImmutable('+3 days'))->format('Y-m-d'),
        ]));
        self::assertEmailCount(1);
        self::assertEmailAddressContains(self::getMailerMessage(), 'To', "parent-$id@exemple.test");
        $crawler = $this->client->followRedirect();
        self::assertStringContainsString('Famille prévenue par e-mail', $crawler->filter('.flash-succes')->text());
        self::assertStringContainsString('Tom PETIT (joueur)', $crawler->filter('main')->text());

        $this->client->loginUser($em->find(Utilisateur::class, $parent->getId()));
        $crawler = $this->client->request('GET', '/espace');
        self::assertStringContainsString('Laver les maillots', $crawler->filter('#taches + .liste-taches')->text());
        $this->client->submit($crawler->selectButton("✓ C'est fait")->form());
        self::assertEmailCount(1);
        $email = self::getMailerMessage();
        self::assertEmailAddressContains($email, 'To', "admin-$id@exemple.test");
        self::assertEmailHtmlBodyContains($email, 'La famille de Tom PETIT');
        $crawler = $this->client->followRedirect();
        self::assertSelectorTextContains('#taches + .liste-taches', 'faite');
        self::assertCount(0, $crawler->selectButton("✓ C'est fait"));
    }
}
