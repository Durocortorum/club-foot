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

        // Le tableau montre le coach avec son seul droit, et l'administrateur « inclus » partout.
        $this->client->loginUser($admin);
        $crawler = $this->client->request('GET', '/utilisateurs/droits');
        self::assertResponseIsSuccessful();
        $ligne = $crawler->filter('table.droits tbody tr')->reduce(fn ($tr) => str_contains($tr->text(), 'Durand'));
        self::assertCount(1, $ligne->filter('button.oui'));
        self::assertSame('ROLE_FORMATION', $ligne->filter('button.oui')->attr('data-role'));
        self::assertNotNull($crawler->filter('button[data-role="ROLE_ADMIN"][data-verrou]')->attr('disabled'));

        // Clic sur « Infirmerie » (réponse JSON, comme dans le navigateur).
        $form = $ligne->filter('button[data-role="ROLE_INFIRMERIE"]')->closest('form')->form();
        $this->client->request('POST', $form->getUri(), $form->getValues(), [], ['HTTP_ACCEPT' => 'application/json']);
        self::assertResponseIsSuccessful();
        $reponse = json_decode($this->client->getResponse()->getContent(), true);
        self::assertEqualsCanonicalizing(['ROLE_FORMATION', 'ROLE_INFIRMERIE'], $reponse['roles']);
        self::assertEqualsCanonicalizing(['ROLE_FORMATION', 'ROLE_INFIRMERIE'], $this->compte($coach)->getRolesAttribues());

        // Retrait de « Formation » par le formulaire classique (sans JavaScript).
        $crawler = $this->client->request('GET', '/utilisateurs/droits');
        $ligne = $crawler->filter('table.droits tbody tr')->reduce(fn ($tr) => str_contains($tr->text(), 'Durand'));
        $this->client->submit($ligne->filter('button[data-role="ROLE_FORMATION"]')->closest('form')->form());
        self::assertResponseRedirects('/utilisateurs/droits');
        $this->client->followRedirect();
        self::assertSelectorTextContains('.flash-succes', 'retiré');
        self::assertSame(['ROLE_INFIRMERIE'], $this->compte($coach)->getRolesAttribues());

        // Impossible de retirer sa propre administration, même en forçant la requête.
        $jeton = $crawler->filter('input[name="_token"]')->attr('value');
        $this->client->request('POST', '/utilisateurs/' . $admin->getId() . '/droit',
            ['role' => 'ROLE_ADMIN', 'actif' => '0', '_token' => $jeton], [], ['HTTP_ACCEPT' => 'application/json']);
        self::assertResponseStatusCodeSame(422);
        self::assertTrue($this->compte($admin)->isAdmin());

        // Droit inconnu ou jeton invalide : refusé.
        $this->client->request('POST', '/utilisateurs/' . $coach->getId() . '/droit',
            ['role' => 'ROLE_SUPER', 'actif' => '1', '_token' => $jeton], [], ['HTTP_ACCEPT' => 'application/json']);
        self::assertResponseStatusCodeSame(422);
        $this->client->request('POST', '/utilisateurs/' . $coach->getId() . '/droit',
            ['role' => 'ROLE_PRO', 'actif' => '1', '_token' => 'faux'], [], ['HTTP_ACCEPT' => 'application/json']);
        self::assertResponseStatusCodeSame(422);
        self::assertSame(['ROLE_INFIRMERIE'], $this->compte($coach)->getRolesAttribues());

        // Un compte sans administration n'ouvre pas le tableau.
        $this->client->loginUser($this->compte($coach));
        $this->client->request('GET', '/utilisateurs/droits');
        self::assertResponseStatusCodeSame(403);
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
