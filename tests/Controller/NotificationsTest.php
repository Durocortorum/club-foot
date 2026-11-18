<?php

namespace App\Tests\Controller;

use App\Entity\AbonnementPush;
use App\Entity\Categorie;
use App\Entity\Club;
use App\Entity\Convocation;
use App\Entity\Joueur;
use App\Entity\Rencontre;
use App\Entity\Utilisateur;
use App\Service\CreationClub;
use App\Service\NotificationsTelephone;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Notifications sur le téléphone : abonnement d'un appareil depuis l'espace famille ou Mon compte, notifications
 * des messages, convocations et réponses (gardées en mémoire en test), désabonnement, manifeste de l'application.
 */
class NotificationsTest extends WebTestCase
{
    private ?int $clubId = null;

    protected function tearDown(): void
    {
        if ($this->clubId) {
            $connexion = static::getContainer()->get(EntityManagerInterface::class)->getConnection();
            foreach (['abonnement_push', 'message', 'convocation', 'rencontre', 'utilisateur', 'joueur', 'categorie'] as $table) {
                $connexion->executeStatement("DELETE FROM $table WHERE club_id = ?", [$this->clubId]);
            }
            $connexion->executeStatement('DELETE FROM club WHERE id = ?', [$this->clubId]);
        }
        parent::tearDown();
    }

    public function testNotifications(): void
    {
        NotificationsTelephone::$envoyees = [];
        $client = static::createClient();
        $id = bin2hex(random_bytes(4));
        $admin = (new Utilisateur())->setEmail("admin-$id@exemple.test")->setNom('Dupont')->setPrenom('Admin');
        $club = (new Club())->setNom("Club Test $id");
        static::getContainer()->get(CreationClub::class)->creer($club, $admin, 'motdepasse-admin');
        $this->clubId = $club->getId();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $u15 = $em->getRepository(Categorie::class)->findOneBy(['club' => $club, 'nom' => 'U15']);
        $lea = (new Joueur())->setClub($club)->setCategorie($u15)->setNom('Martin')->setPrenom('Léa')->setEmailParent1("parent-$id@exemple.test");
        $em->persist($lea);
        $parent = (new Utilisateur())->setClub($club)->setFamille(true)->setEmail("parent-$id@exemple.test")->setNom('Martin')->setPrenom('Famille')->setPassword('x')->addJoueur($lea);
        $em->persist($parent);
        $match = (new Rencontre())->setClub($club)->setCategorie($u15)->setDate(new \DateTimeImmutable('+3 days'))->setHeure('15h00')
            ->setDomicile("CLUB TEST $id")->setExterieur('Reims');
        $em->persist($match);
        $convocation = (new Convocation($match))->setClub($club)->setJoueurs([$lea]);
        foreach ($convocation->getConvoques() as $c) {
            $c->setClub($club);
        }
        $em->persist($convocation);
        $em->flush();

        // Manifeste de l'application installable, lisible sans être connecté.
        $client->request('GET', '/manifest.webmanifest');
        self::assertResponseIsSuccessful();
        $manifeste = json_decode($client->getResponse()->getContent(), true);
        self::assertSame('standalone', $manifeste['display']);

        // Le parent abonne son téléphone depuis son espace.
        $client->loginUser($parent);
        $crawler = $client->request('GET', '/espace');
        $carte = $crawler->filter('[data-controller="notifications"]');
        self::assertCount(1, $carte);
        $jeton = $carte->attr('data-notifications-jeton-value');
        $telephone = ['endpoint' => 'https://fcm.googleapis.com/fcm/send/abc' . $id, 'keys' => ['p256dh' => str_repeat('B', 87), 'auth' => str_repeat('a', 22)]];
        $this->poster($client, '/notifications/abonnement', $telephone + ['_token' => 'faux']);
        self::assertResponseStatusCodeSame(403);
        $this->poster($client, '/notifications/abonnement', $telephone + ['_token' => $jeton], 'Mozilla/5.0 (Linux; Android 14)');
        self::assertResponseIsSuccessful();
        $abonnement = $em->getRepository(AbonnementPush::class)->findOneBy(['utilisateur' => $parent]);
        self::assertSame('Android', $abonnement->getAppareil());
        // Même téléphone abonné une seconde fois : pas de doublon.
        $this->poster($client, '/notifications/abonnement', $telephone + ['_token' => $jeton]);
        self::assertCount(1, $em->getRepository(AbonnementPush::class)->findBy(['utilisateur' => $parent]));
        $this->poster($client, '/notifications/essai', ['_token' => $jeton]);
        self::assertTrue(json_decode($client->getResponse()->getContent(), true)['ok']);

        // L'éducateur abonne aussi son téléphone (Mon compte), écrit à l'équipe et envoie la convocation.
        $client->loginUser($admin);
        $crawler = $client->request('GET', '/mon-compte');
        $jetonAdmin = $crawler->filter('[data-controller="notifications"]')->attr('data-notifications-jeton-value');
        $this->poster($client, '/notifications/abonnement', ['endpoint' => 'https://web.push.apple.com/xyz' . $id, 'keys' => ['p256dh' => str_repeat('C', 87), 'auth' => str_repeat('b', 22)], '_token' => $jetonAdmin], 'Mozilla/5.0 (iPhone)');
        NotificationsTelephone::$envoyees = [];
        $crawler = $client->request('GET', '/messages/nouveau?categorie=' . $u15->getId());
        $client->submit($crawler->selectButton('Envoyer')->form(), ['form[cible]' => 'categorie', 'form[categorie]' => $u15->getId(), 'form[sujet]' => 'Entraînement déplacé', 'form[corps]' => "Mercredi, rendez-vous au gymnase.\nMerci."]);
        $message = $client->followRedirect();
        $pourParent = array_values(array_filter(NotificationsTelephone::$envoyees, fn ($n) => $n['compte'] === "parent-$id@exemple.test"));
        self::assertCount(1, $pourParent);
        self::assertSame('Entraînement déplacé', $pourParent[0]['titre']);
        self::assertStringContainsString('Mercredi, rendez-vous au gymnase. Merci.', $pourParent[0]['texte']);
        self::assertStringContainsString('/espace/messages/', $pourParent[0]['url']);

        NotificationsTelephone::$envoyees = [];
        $crawler = $client->request('GET', '/matchs/' . $match->getId() . '/convocation');
        $client->submit($crawler->selectButton('Enregistrer et envoyer')->form());
        self::assertSame('Léa convoqué(e)', NotificationsTelephone::$envoyees[0]['titre'] ?? null);
        self::assertStringContainsString('Répondez présent ou absent', NotificationsTelephone::$envoyees[0]['texte']);

        // Le parent répond au message : l'éducateur est prévenu sur son téléphone.
        NotificationsTelephone::$envoyees = [];
        $client->loginUser($parent);
        $idMessage = (int) preg_replace('#.*/messages/(\d+).*#', '$1', $pourParent[0]['url']);
        $crawler = $client->request('GET', '/espace/messages/' . $idMessage);
        $client->submit($crawler->filter('form.repondre')->form(), ['corps' => 'Bien noté, merci !']);
        self::assertSame("admin-$id@exemple.test", NotificationsTelephone::$envoyees[0]['compte'] ?? null);
        self::assertStringStartsWith('Réponse de ', NotificationsTelephone::$envoyees[0]['titre']);

        // Désabonnement du téléphone du parent (jeton de sa session actuelle).
        $jeton = $client->request('GET', '/espace')->filter('[data-controller="notifications"]')->attr('data-notifications-jeton-value');
        $this->poster($client, '/notifications/desabonnement', ['endpoint' => $telephone['endpoint'], '_token' => $jeton]);
        self::assertResponseIsSuccessful();
        self::assertCount(0, $em->getRepository(AbonnementPush::class)->findBy(['utilisateur' => $parent]));
    }

    /** @param array<string, mixed> $donnees */
    private function poster(KernelBrowser $client, string $url, array $donnees, string $agent = 'Mozilla/5.0'): void
    {
        $client->request('POST', $url, [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_USER_AGENT' => $agent], json_encode($donnees));
    }
}
