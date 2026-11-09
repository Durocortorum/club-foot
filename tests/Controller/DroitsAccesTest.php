<?php

namespace App\Tests\Controller;

use App\Entity\Club;
use App\Entity\Utilisateur;
use App\Service\CreationClub;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Tableau des droits d'accès : un administrateur donne ou retire un droit d'un clic,
 * sans pouvoir retirer sa propre administration. Club fictif supprimé à la fin.
 */
class DroitsAccesTest extends WebTestCase
{
    use OuvertureMedicaleTrait;

    private KernelBrowser $client;
    private ?int $clubId = null;

    protected function setUp(): void
    {
        $this->client = static::createClient();
    }

    protected function tearDown(): void
    {
        if ($this->clubId) {
            $connexion = $this->em()->getConnection();
            foreach (['rencontre', 'joueur', 'club'] as $table) {
                $connexion->executeStatement("DELETE FROM $table WHERE " . ('club' === $table ? 'id' : 'club_id') . ' = ?', [$this->clubId]);
            }
        }
        parent::tearDown();
    }

    public function testBasculerUnDroit(): void
    {
        $id = bin2hex(random_bytes(4));
        $admin = (new Utilisateur())->setEmail("admin-$id@exemple.test")->setNom('Test')->setPrenom('Admin');
        $club = (new Club())->setNom("Club Test $id");
        static::getContainer()->get(CreationClub::class)->creer($club, $admin, 'motdepasse-admin');
        $this->clubId = $club->getId();

        $coach = (new Utilisateur())->setEmail("coach-$id@exemple.test")->setNom('Durand')->setPrenom('Coach')
            ->setRolesAttribues(['ROLE_FORMATION']);
        $coach->setClub($club);
        $this->em()->persist($coach);
        $this->em()->flush();

        // Page Apparence : coordonnées du club (bas des e-mails) ; une adresse de site invalide est refusée.
        $this->client->loginUser($admin);
        $crawler = $this->client->request('GET', '/club');
        self::assertResponseIsSuccessful();
        $form = $crawler->selectButton('Enregistrer')->form();
        $this->client->submit($form, ['club[telephone]' => '03 26 00 00 00', 'club[emailContact]' => 'contact@exemple.test', 'club[siteWeb]' => 'pas une adresse']);
        self::assertResponseStatusCodeSame(422);
        $this->client->submit($form, ['club[telephone]' => '03 26 00 00 00', 'club[emailContact]' => 'contact@exemple.test', 'club[siteWeb]' => 'https://www.exemple.test']);
        self::assertResponseRedirects('/club');
        $this->em()->clear();
        $club = $this->em()->find(\App\Entity\Club::class, $this->clubId);
        self::assertSame('contact@exemple.test', $club->getEmailContact());
        self::assertSame('https://www.exemple.test', $club->getSiteWeb());

        // Le tableau montre le coach avec son seul droit, et l'administrateur « inclus » partout.
        $crawler = $this->client->request('GET', '/utilisateurs/droits');
        self::assertResponseIsSuccessful();
        $ligne = $this->ligne($crawler);
        self::assertCount(1, $ligne->filter('button.oui'));
        self::assertSame('ROLE_FORMATION', $ligne->filter('button.oui')->attr('data-cle'));
        self::assertNotNull($crawler->filter('button[data-cle="ROLE_ADMIN"][data-verrou]')->attr('disabled'));
        self::assertSame('—', trim($ligne->filter('button[data-cle="infirmerie"]')->text()));

        // Infirmerie : un clic → « voir » (réponse JSON, comme dans le navigateur), un deuxième → « modifier ».
        $form = $ligne->filter('button[data-cle="infirmerie"]')->closest('form')->form();
        $this->client->request('POST', $form->getUri(), $form->getValues(), [], ['HTTP_ACCEPT' => 'application/json']);
        self::assertResponseIsSuccessful();
        $reponse = json_decode($this->client->getResponse()->getContent(), true);
        self::assertEqualsCanonicalizing(['ROLE_FORMATION', 'ROLE_INFIRMERIE_LECTURE'], $reponse['roles']);
        $ligne = $this->ligne($this->client->request('GET', '/utilisateurs/droits'));
        self::assertSame('voir', trim($ligne->filter('button[data-cle="infirmerie"]')->text()));
        $this->client->submit($ligne->filter('button[data-cle="infirmerie"]')->closest('form')->form());
        self::assertEqualsCanonicalizing(['ROLE_FORMATION', 'ROLE_INFIRMERIE'], $this->compte($coach)->getRolesAttribues());
        self::assertSame(2, $this->compte($coach)->getNiveau('infirmerie'));

        // Retrait de « Formation » par le formulaire classique (sans JavaScript).
        $crawler = $this->client->request('GET', '/utilisateurs/droits');
        $ligne = $this->ligne($crawler);
        self::assertSame('modifier', trim($ligne->filter('button[data-cle="infirmerie"]')->text()));
        $this->client->submit($ligne->filter('button[data-cle="ROLE_FORMATION"]')->closest('form')->form());
        self::assertResponseRedirects('/utilisateurs/droits');
        $this->client->followRedirect();
        self::assertSelectorTextContains('.flash-succes', 'retiré');
        self::assertSame(['ROLE_INFIRMERIE'], $this->compte($coach)->getRolesAttribues());

        // Impossible de retirer sa propre administration, même en forçant la requête.
        $jeton = $crawler->filter('input[name="_token"]')->attr('value');
        $this->client->request('POST', '/utilisateurs/' . $admin->getId() . '/droit',
            ['droit' => 'ROLE_ADMIN', 'niveau' => '0', '_token' => $jeton], [], ['HTTP_ACCEPT' => 'application/json']);
        self::assertResponseStatusCodeSame(422);
        self::assertTrue($this->compte($admin)->isAdmin());

        // Droit inconnu, niveau impossible ou jeton invalide : refusé.
        foreach ([['ROLE_SUPER', '1', $jeton], ['ROLE_PRO', '2', $jeton], ['scolarite', '3', $jeton], ['ROLE_PRO', '1', 'faux']] as [$droit, $niveau, $j]) {
            $this->client->request('POST', '/utilisateurs/' . $coach->getId() . '/droit',
                ['droit' => $droit, 'niveau' => $niveau, '_token' => $j], [], ['HTTP_ACCEPT' => 'application/json']);
            self::assertResponseStatusCodeSame(422, "$droit / $niveau");
        }
        self::assertSame(['ROLE_INFIRMERIE'], $this->compte($coach)->getRolesAttribues());

        // « Voir » seulement : la page Infirmerie s'ouvre (après le code e-mail), sans bouton d'ajout ; la saisie est refusée.
        $this->client->request('POST', '/utilisateurs/' . $coach->getId() . '/droit',
            ['droit' => 'infirmerie', 'niveau' => '1', '_token' => $jeton], [], ['HTTP_ACCEPT' => 'application/json']);
        $this->client->loginUser($this->compte($coach));
        $this->client->request('GET', '/infirmerie');
        self::assertResponseRedirects('/medical/code?retour=/infirmerie');
        $this->ouvrirMedical($this->client);
        $this->client->request('GET', '/infirmerie');
        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('a[href="/infirmerie/blessures/nouvelle"]');
        $this->client->request('GET', '/infirmerie/blessures/nouvelle');
        self::assertResponseStatusCodeSame(403);
        $this->client->request('GET', '/scolarite');
        self::assertResponseStatusCodeSame(403);

        // Un compte sans administration n'ouvre pas le tableau.
        $this->client->loginUser($this->compte($coach));
        $this->client->request('GET', '/utilisateurs/droits');
        self::assertResponseStatusCodeSame(403);
    }

    private function ligne(\Symfony\Component\DomCrawler\Crawler $crawler): \Symfony\Component\DomCrawler\Crawler
    {
        return $crawler->filter('table.droits tbody tr')->reduce(fn ($tr) => str_contains($tr->text(), 'DURAND'));
    }

    private function compte(Utilisateur $u): Utilisateur
    {
        $em = $this->em();
        $em->clear();

        return $em->getRepository(Utilisateur::class)->find($u->getId());
    }

    private function em(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }
}
