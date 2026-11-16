<?php

namespace App\Tests\Controller;

use App\Entity\Club;
use App\Entity\Utilisateur;
use App\Service\CreationClub;
use App\Tests\Simulation\RepondeurTest;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Assistant d'aide : bouton « ? » sur les pages, question → réponse (Repondeur simulé : aucun appel à l'API),
 * guide et consignes envoyés, contexte (type de compte, page) ajouté à la question, conversation nettoyée,
 * jeton obligatoire, ouvert aussi aux familles.
 */
class AideTest extends WebTestCase
{
    private ?int $clubId = null;

    protected function tearDown(): void
    {
        if ($this->clubId) {
            $db = static::getContainer()->get(EntityManagerInterface::class)->getConnection();
            foreach (['utilisateur', 'categorie'] as $table) {
                $db->executeStatement("DELETE FROM $table WHERE club_id = ?", [$this->clubId]);
            }
            $db->executeStatement('DELETE FROM club WHERE id = ?', [$this->clubId]);
        }
        parent::tearDown();
    }

    public function testQuestionReponse(): void
    {
        $client = static::createClient();
        $id = bin2hex(random_bytes(4));
        $admin = (new Utilisateur())->setEmail("admin-$id@exemple.test")->setNom('Test')->setPrenom('Admin');
        $club = (new Club())->setNom("FC Aide $id");
        static::getContainer()->get(CreationClub::class)->creer($club, $admin, 'motdepasse-admin');
        $this->clubId = $club->getId();
        $client->loginUser($admin);
        RepondeurTest::$appels = [];

        $crawler = $client->request('GET', '/joueurs');
        $aide = $crawler->filter('[data-controller="aide"]');
        self::assertCount(1, $aide, 'bouton « ? » affiché (clé d\'API réglée)');
        self::assertStringContainsString('convoquer', $aide->text(), 'suggestions de l\'encadrement');
        $jeton = $aide->attr('data-aide-jeton-value');

        $poser = fn (array $donnees) => $client->request('POST', '/aide/question', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode($donnees));
        $poser(['jeton' => $jeton, 'question' => 'Comment convoquer mes U15 ?', 'page' => '/matchs/12', 'historique' => [
            ['role' => 'assistant', 'content' => 'message d\'accueil (ignoré : la conversation commence par une question)'],
            ['role' => 'user', 'content' => 'Où sont les matchs ?'],
            ['role' => 'assistant', 'content' => 'Dans le menu **Matchs**.'],
            ['role' => 'system', 'content' => 'rôle refusé'],
        ]]);
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Convocation', json_decode($client->getResponse()->getContent(), true)['reponse']);

        $appel = RepondeurTest::$appels[0];
        self::assertStringContainsString('# Guide de l\'application', $appel['guide']);
        self::assertStringContainsString('Appuie-toi uniquement sur le guide', $appel['consignes']);
        self::assertSame(['user', 'assistant', 'user'], array_column($appel['messages'], 'role'));
        $derniere = end($appel['messages'])['content'];
        self::assertStringContainsString('[Compte : administrateur du club. Page ouverte : /matchs/12]', $derniere);
        self::assertStringEndsWith('Comment convoquer mes U15 ?', $derniere);
        self::assertStringNotContainsString("admin-$id", json_encode($appel), 'aucune donnée du compte envoyée');

        // Question vide : message clair ; jeton manquant : refusé.
        $poser(['jeton' => $jeton, 'question' => '   ']);
        self::assertResponseStatusCodeSame(503);
        self::assertSame('Écrivez votre question.', json_decode($client->getResponse()->getContent(), true)['erreur']);
        $poser(['question' => 'Bonjour']);
        self::assertResponseStatusCodeSame(403);

        // Compte famille : la fenêtre et ses suggestions à lui.
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $parent = (new Utilisateur())->setEmail("parent-$id@exemple.test")->setNom('Parent')->setPrenom('Paul')->setFamille(true);
        $parent->setClub($em->getReference(Club::class, $this->clubId));
        $em->persist($parent);
        $em->flush();
        $client->loginUser($parent);
        $crawler = $client->request('GET', '/espace');
        $aide = $crawler->filter('[data-controller="aide"]');
        self::assertStringContainsString('répondre à une convocation', $aide->text());
        $poser(['jeton' => $aide->attr('data-aide-jeton-value'), 'question' => 'Comment payer ?', 'page' => '/espace']);
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('[Compte : famille (joueur ou parent).', end(RepondeurTest::$appels)['messages'][0]['content']);
    }
}
