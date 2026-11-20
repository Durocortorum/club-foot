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
 * Suspension d'un compte par l'administrateur : plus de connexion (déconnecté s'il l'était), plus d'e-mails,
 * plus de responsabilité sur l'équipe ; date de fin facultative ; réactivation qui rend tout comme avant.
 */
class SuspensionCompteTest extends WebTestCase
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
            $connexion->executeStatement('UPDATE categorie SET responsable_matchs_id = NULL WHERE club_id = ?', [$this->clubId]);
            $connexion->executeStatement('DELETE FROM utilisateur WHERE club_id = ?', [$this->clubId]);
            $connexion->executeStatement('DELETE FROM club WHERE id = ?', [$this->clubId]);
        }
        parent::tearDown();
    }

    public function testSuspendreEtReactiver(): void
    {
        $id = bin2hex(random_bytes(4));
        $admin = (new Utilisateur())->setEmail("admin-$id@exemple.test")->setNom('Admin')->setPrenom('Paul');
        $club = (new Club())->setNom("Club Test $id");
        static::getContainer()->get(CreationClub::class)->creer($club, $admin, 'motdepasse-admin');
        $this->clubId = $club->getId();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $equipe = $em->getRepository(Categorie::class)->findOneBy(['club' => $club], ['ordre' => 'ASC']);
        $role = Categorie::SECTEURS[$equipe->getSecteur()]['role'];
        $hasher = static::getContainer()->get('security.user_password_hasher');

        $educ = (new Utilisateur())->setClub($club)->setEmail("educ-$id@exemple.test")->setNom('Coach')->setPrenom('Marc')->setRoles([$role]);
        $educ->setPassword($hasher->hashPassword($educ, 'motdepasse-educ'));
        $autre = (new Utilisateur())->setClub($club)->setEmail("autre-$id@exemple.test")->setNom('Aide')->setPrenom('Julie')->setRoles([$role]);
        $equipe->setResponsableMatchs($educ);
        $match = (new Rencontre())->setClub($club)->setCategorie($equipe)->setDate(new \DateTimeImmutable('today'))
            ->setDomicile("CLUB TEST $id")->setExterieur('FC METZ');
        foreach ([$educ, $autre, $match] as $o) {
            $em->persist($o);
        }
        $em->flush();
        $voir = '/matchs/' . $match->getId();

        // Marc est connecté ; pendant ce temps, l'administrateur suspend son compte jusqu'à demain.
        $this->client->loginUser($educ);
        $this->client->request('GET', '/mon-compte');
        self::assertResponseIsSuccessful();
        $this->client->loginUser($admin);
        $form = $this->client->request('GET', '/utilisateurs/' . $educ->getId() . '/modifier')->selectButton('Suspendre le compte')->form();
        $form['jusqu_au'] = (new \DateTimeImmutable('tomorrow'))->format('Y-m-d');
        $form['motif'] = 'Sanction';
        $this->client->submit($form);
        $crawler = $this->client->followRedirect();
        self::assertSelectorTextContains('#suspension', 'Compte suspendu depuis le');
        self::assertSelectorTextContains('#suspension', 'Motif : Sanction');
        $this->client->request('GET', '/utilisateurs');
        self::assertSelectorTextContains('table', 'suspendu jusqu\'au');

        // Pendant la suspension, l'équipe se rouvre au secteur : Julie peut modifier le match.
        $this->client->loginUser($autre);
        $this->client->request('GET', $voir . '/modifier');
        self::assertResponseIsSuccessful();

        // Marc, encore connecté, est déconnecté à sa page suivante, avec le message.
        $this->client->loginUser($em->find(Utilisateur::class, $educ->getId()));
        $this->client->request('GET', '/mon-compte');
        self::assertResponseRedirects('/connexion');
        $this->client->followRedirect();
        self::assertSelectorTextContains('.flash-erreur', 'Votre compte est suspendu jusqu\'au');
        // Et il ne peut plus se connecter.
        $form = $this->client->request('GET', '/connexion')->filter('form')->form();
        $form['email'] = "educ-$id@exemple.test";
        $form['mot_de_passe'] = 'motdepasse-educ';
        $this->client->submit($form);
        $this->client->followRedirect();
        self::assertSelectorTextContains('.flash-erreur', 'Votre compte est suspendu');

        // Réactivation : il se reconnecte et tient de nouveau l'équipe.
        $this->client->loginUser($admin);
        $this->client->request('GET', '/utilisateurs/' . $educ->getId() . '/modifier');
        $this->client->submit($this->client->getCrawler()->selectButton('Réactiver le compte')->form());
        $this->client->getCookieJar()->clear();
        $form =$this->client->request('GET', '/connexion')->filter('form')->form();
        $form['email'] = "educ-$id@exemple.test";
        $form['mot_de_passe'] = 'motdepasse-educ';
        $this->client->submit($form);
        self::assertResponseRedirects();
        $this->client->loginUser($autre);
        $this->client->request('GET', $voir . '/modifier');
        self::assertResponseStatusCodeSame(403);
    }

    /** Une suspension dont la date de fin est passée ne compte plus : le compte est réactivé tout seul. */
    public function testFinDeSuspension(): void
    {
        $u = (new Utilisateur())->suspendre(new \DateTimeImmutable('yesterday'), null);
        self::assertFalse($u->isSuspendu());
        self::assertTrue($u->suspendre(new \DateTimeImmutable('today'), null)->isSuspendu());
        self::assertTrue($u->suspendre(null, 'Sans date')->isSuspendu());
        self::assertFalse($u->reactiver()->isSuspendu());
    }
}
