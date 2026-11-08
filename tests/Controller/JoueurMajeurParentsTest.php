<?php

namespace App\Tests\Controller;

use App\Entity\Categorie;
use App\Entity\Club;
use App\Entity\Joueur;
use App\Entity\Utilisateur;
use App\Service\CreationClub;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Joueur majeur : le club n'invite que lui ; c'est lui qui donne ou retire l'accès de ses parents,
 * depuis son espace. Club fictif supprimé à la fin.
 */
class JoueurMajeurParentsTest extends WebTestCase
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

    public function testLeJoueurMajeurDecide(): void
    {
        $id = bin2hex(random_bytes(4));
        $admin = (new Utilisateur())->setEmail("admin-$id@exemple.test")->setNom('Test')->setPrenom('Admin');
        $club = (new Club())->setNom("Club Test $id");
        static::getContainer()->get(CreationClub::class)->creer($club, $admin, 'motdepasse-admin');
        $this->clubId = $club->getId();

        [$moi, $parent] = ["joueur-$id@exemple.test", "parent-$id@exemple.test"];
        $categorie = $this->em()->getRepository(Categorie::class)->findOneBy(['club' => $club], ['ordre' => 'ASC']);
        $joueur = (new Joueur())->setClub($club)->setCategorie($categorie)->setNom('Martin')->setPrenom('Hugo')
            ->setDateNaissance(new \DateTimeImmutable('-20 years'))->setEmailJoueur($moi)->setEmailParent1($parent);
        $this->em()->persist($joueur);
        $this->em()->flush();

        // --- Fiche : seul le joueur est proposé à l'invitation, avec l'explication.
        $this->client->loginUser($admin);
        $crawler = $this->client->request('GET', '/joueurs/' . $joueur->getId());
        self::assertSelectorTextContains('#famille', 'majeur');
        // Majeur avec un e-mail de parent saisi : il reste visible (fiche et formulaire) pour pouvoir le corriger.
        self::assertSelectorTextContains('#fiche', $parent);
        $this->client->request('GET', '/joueurs/' . $joueur->getId() . '/modifier');
        self::assertSelectorNotExists('.parents[hidden]');
        self::assertInputValueSame('joueur[emailParent1]', $parent);
        $crawler = $this->client->request('GET', '/joueurs/' . $joueur->getId());
        self::assertCount(1, $crawler->filter("#famille form input[value=\"$moi\"]"));
        self::assertCount(0, $crawler->filter("#famille form input[value=\"$parent\"]"));

        // Une invitation forcée du parent par le club est refusée.
        $jeton = $crawler->filter('#famille form input[name="_token"]')->attr('value');
        $this->client->request('POST', '/joueurs/' . $joueur->getId() . '/famille/inviter', ['email' => $parent, '_token' => $jeton]);
        $this->client->followRedirect();
        self::assertSelectorTextContains('.flash-erreur', 'majeur');
        self::assertNull($this->compte($parent));

        // Le club invite le joueur ; il active son compte.
        $this->client->request('POST', '/joueurs/' . $joueur->getId() . '/famille/inviter', ['email' => $moi, '_token' => $jeton]);
        $compte = $this->compte($moi);
        $compte->setPassword('inutile-en-test');
        $this->em()->flush();

        // --- Espace du joueur : rubrique « Accès de mes parents », il donne l'accès.
        $this->client->loginUser($this->compte($moi));
        $crawler = $this->client->request('GET', '/espace');
        self::assertSelectorTextContains('#parents-' . $joueur->getId(), 'Accès de mes parents');
        self::assertSelectorTextContains('main', $parent);
        $this->client->submit($crawler->selectButton('Donner l\'accès')->form());
        self::assertEmailCount(1);
        self::assertEmailAddressContains(self::getMailerMessage(), 'to', $parent);
        $this->client->followRedirect();
        self::assertSelectorTextContains('.flash-succes', $parent);
        self::assertNotNull($this->compte($parent));

        // Il retire l'accès : le compte du parent disparaît (il ne suivait que lui).
        $crawler = $this->client->request('GET', '/espace');
        $this->client->submit($crawler->selectButton('Retirer l\'accès')->form());
        self::assertNull($this->compte($parent));

        // Un compte parent ne voit pas la rubrique et ne peut pas inviter à la place du joueur.
        $acces = static::getContainer()->get(\App\Service\AccesFamille::class);
        $joueur = $this->em()->find(Joueur::class, $joueur->getId());
        $acces->inviter($joueur, $parent, 'parent1');
        $compteParent = $this->compte($parent)->setPassword('inutile-en-test');
        $this->em()->flush();
        $this->client->loginUser($compteParent);
        $this->client->request('GET', '/espace');
        self::assertSelectorNotExists('#parents-' . $joueur->getId());
        $this->client->request('POST', '/espace/parents/' . $joueur->getId() . '/inviter', ['qui' => 'parent1', '_token' => 'x']);
        self::assertResponseStatusCodeSame(403);

        // Joueur mineur : rien ne change, le club invite les parents.
        $this->em()->find(Joueur::class, $joueur->getId())->setDateNaissance(new \DateTimeImmutable('-15 years'));
        $this->em()->flush();
        $this->client->loginUser($admin);
        $crawler = $this->client->request('GET', '/joueurs/' . $joueur->getId());
        self::assertSelectorTextNotContains('#famille', 'majeur');
    }

    private function compte(string $email): ?Utilisateur
    {
        $em = $this->em();
        $em->clear();

        return $em->getRepository(Utilisateur::class)->findOneBy(['email' => $email]);
    }

    private function em(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }
}
