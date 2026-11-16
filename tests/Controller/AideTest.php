<?php

namespace App\Tests\Controller;

use App\Entity\Club;
use App\Entity\Utilisateur;
use App\Service\CreationClub;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Assistant d'aide : bouton « ? » sur les pages, réponse tirée du guide de l'application (sans service extérieur),
 * rubriques famille d'abord pour les familles, message quand rien ne correspond, jeton obligatoire.
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

    public function testQuestionsReponses(): void
    {
        $client = static::createClient();
        $id = bin2hex(random_bytes(4));
        $admin = (new Utilisateur())->setEmail("admin-$id@exemple.test")->setNom('Test')->setPrenom('Admin');
        $club = (new Club())->setNom("FC Aide $id");
        static::getContainer()->get(CreationClub::class)->creer($club, $admin, 'motdepasse-admin');
        $this->clubId = $club->getId();
        $client->loginUser($admin);

        $crawler = $client->request('GET', '/joueurs');
        $aide = $crawler->filter('[data-controller="aide"]');
        self::assertCount(1, $aide, 'bouton « ? » sur les pages');
        self::assertStringContainsString('convoquer', $aide->text(), 'suggestions de l\'encadrement');
        $jeton = $aide->attr('data-aide-jeton-value');
        $poser = function (string $question, ?string $avecJeton) use ($client): array {
            $client->request('POST', '/aide/question', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode(['jeton' => $avecJeton, 'question' => $question]));

            return json_decode($client->getResponse()->getContent(), true) ?? [];
        };

        // Questions de l'encadrement : la bonne rubrique du guide.
        self::assertStringStartsWith('**Convocations**', $poser('Comment convoquer mes joueurs à un match ?', $jeton)['reponse']);
        self::assertStringContainsString('Importer des joueurs', $poser('Comment importer mes joueurs depuis Excel ?', $jeton)['reponse']);
        self::assertStringContainsString('Match en direct', $poser('statistiques en direct sur la tablette', $jeton)['reponse']);
        self::assertStringContainsString('Apparence', $poser('mettre le logo du club', $jeton)['reponse']);
        // Rien de proche : le dire et proposer les sujets du guide.
        $rien = $poser('xyzzy plouf', $jeton)['reponse'];
        self::assertStringStartsWith('Je n\'ai pas trouvé', $rien);
        self::assertStringContainsString('- Convocations', $rien);
        // Question vide : message clair ; sans jeton : refusé.
        self::assertSame('Écrivez votre question.', $poser('  ', $jeton)['erreur']);
        self::assertResponseStatusCodeSame(422);
        $poser('Bonjour', null);
        self::assertResponseStatusCodeSame(403);

        // Compte famille : ses suggestions, et les rubriques de l'espace famille d'abord.
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $parent = (new Utilisateur())->setEmail("parent-$id@exemple.test")->setNom('Parent')->setPrenom('Paul')->setFamille(true);
        $parent->setClub($em->getReference(Club::class, $this->clubId));
        $em->persist($parent);
        $em->flush();
        $client->loginUser($parent);
        $aide = $client->request('GET', '/espace')->filter('[data-controller="aide"]');
        self::assertStringContainsString('répondre à une convocation', $aide->text());
        $reponse = $poser('Comment répondre à une convocation ?', $aide->attr('data-aide-jeton-value'))['reponse'];
        self::assertStringStartsWith('**Espace famille', $reponse);
        self::assertStringContainsString('Présent', $reponse);
    }
}
