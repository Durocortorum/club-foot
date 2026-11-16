<?php

namespace App\Tests\Controller;

use App\Entity\ActionMatch;
use App\Entity\Categorie;
use App\Entity\Club;
use App\Entity\Joueur;
use App\Entity\Rencontre;
use App\Entity\Utilisateur;
use App\Service\CreationClub;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Match en direct : page de saisie (joueurs de l'équipe), envoi des actions (rejouable sans doublon, correction,
 * suppression), jeton obligatoire, bilan (par joueur, carte), buts reportés sur la feuille de match.
 */
class MatchDirectTest extends WebTestCase
{
    private ?int $clubId = null;

    protected function tearDown(): void
    {
        if ($this->clubId) {
            $db = static::getContainer()->get(EntityManagerInterface::class)->getConnection();
            $db->executeStatement('SET FOREIGN_KEY_CHECKS = 0');
            foreach (['action_match', 'but', 'rencontre', 'utilisateur', 'joueur', 'categorie'] as $table) {
                $db->executeStatement("DELETE FROM $table WHERE club_id = ?", [$this->clubId]);
            }
            $db->executeStatement('DELETE FROM club WHERE id = ?', [$this->clubId]);
            $db->executeStatement('SET FOREIGN_KEY_CHECKS = 1');
        }
        parent::tearDown();
    }

    public function testSaisieEnDirectEtBilan(): void
    {
        $client = static::createClient();
        $id = bin2hex(random_bytes(4));
        $admin = (new Utilisateur())->setEmail("admin-$id@exemple.test")->setNom('Test')->setPrenom('Admin');
        $club = (new Club())->setNom("FC Direct $id");
        static::getContainer()->get(CreationClub::class)->creer($club, $admin, 'motdepasse-admin');
        $this->clubId = $club->getId();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $u15 = $em->getRepository(Categorie::class)->findOneBy(['club' => $club, 'nom' => 'U15']);
        $tom = (new Joueur())->setClub($club)->setCategorie($u15)->setNom('Petit')->setPrenom('Tom');
        $leo = (new Joueur())->setClub($club)->setCategorie($u15)->setNom('Durand')->setPrenom('Léo');
        $match = (new Rencontre())->setClub($club)->setCategorie($u15)->setDate(new \DateTimeImmutable('today'))
            ->setDomicile("FC DIRECT $id")->setExterieur('AS ADVERSE');
        foreach ([$tom, $leo, $match] as $e) {
            $em->persist($e);
        }
        $em->flush();
        [$matchId, $tomId, $leoId] = [$match->getId(), $tom->getId(), $leo->getId()];
        $client->loginUser($admin);

        // Page de saisie : les joueurs de l'équipe (pas de feuille ni de convocation), la configuration pour la tablette.
        $crawler = $client->request('GET', "/matchs/$matchId/direct");
        self::assertResponseIsSuccessful();
        $config = json_decode($crawler->filter('[data-controller="direct"]')->attr('data-direct-config-value'), true);
        self::assertEqualsCanonicalizing([$tomId, $leoId], array_column($config['joueurs'], 'id'));
        self::assertSame(80, $config['duree'], 'U15 : 2 × 40 minutes');

        $envoyer = fn (array $donnees) => $client->request('POST', "/matchs/$matchId/direct/enregistrer", [], [], ['CONTENT_TYPE' => 'application/json'],
            json_encode($donnees + ['jeton' => $config['jeton']]));
        $actions = [
            ['uuid' => '11111111-1111-4111-8111-111111111111', 'joueur' => $tomId, 'type' => 'BP', 'periode' => 1, 'minute' => 3, 'zx' => 2, 'zy' => 1],
            ['uuid' => '22222222-2222-4222-8222-222222222222', 'joueur' => $leoId, 'type' => 'DG', 'periode' => 1, 'minute' => 5, 'zx' => 1, 'zy' => 0],
            ['uuid' => '33333333-3333-4333-8333-333333333333', 'joueur' => $tomId, 'type' => 'B', 'periode' => 1, 'minute' => 43, 'zx' => 5, 'zy' => 1],
            ['uuid' => '44444444-4444-4444-8444-444444444444', 'joueur' => null, 'type' => 'BA', 'periode' => 2, 'minute' => 60, 'zx' => 0, 'zy' => 1],
            // Refusées : joueur hors du match, action inconnue.
            ['uuid' => '55555555-5555-4555-8555-555555555555', 'joueur' => 999999, 'type' => 'BP', 'periode' => 1, 'minute' => 7, 'zx' => 2, 'zy' => 1],
            ['uuid' => '66666666-6666-4666-8666-666666666666', 'joueur' => $tomId, 'type' => 'XX', 'periode' => 1, 'minute' => 7, 'zx' => 2, 'zy' => 1],
        ];
        $envoyer(['actions' => $actions, 'infos' => ['meteo' => 'Pluie', 'terrain' => 'Synthétique']]);
        self::assertResponseIsSuccessful();
        self::assertSame(4, json_decode($client->getResponse()->getContent(), true)['total']);
        // Réseau coupé puis rétabli : le même envoi rejoué ne crée pas de doublon.
        $envoyer(['actions' => $actions]);
        self::assertSame(4, json_decode($client->getResponse()->getContent(), true)['total']);
        // Correction (même uuid : la bonne passe devient une mauvaise passe) et suppression du duel.
        $envoyer(['actions' => [['type' => 'MP'] + $actions[0]], 'supprimes' => ['22222222-2222-4222-8222-222222222222']]);
        self::assertSame(3, json_decode($client->getResponse()->getContent(), true)['total']);
        $em->clear();
        $types = array_map(fn (ActionMatch $a) => $a->getType(), $em->getRepository(ActionMatch::class)->findBy(['rencontre' => $matchId], ['minute' => 'ASC']));
        self::assertSame(['MP', 'B', 'BA'], $types);
        self::assertSame('Pluie', $em->find(Rencontre::class, $matchId)->getMeteo());

        // Jeton manquant : refusé.
        $client->request('POST', "/matchs/$matchId/direct/enregistrer", [], [], ['CONTENT_TYPE' => 'application/json'], json_encode(['actions' => $actions]));
        self::assertResponseStatusCodeSame(403);

        // Bilan : score, ligne du joueur, carte du terrain.
        $crawler = $client->request('GET', "/matchs/$matchId/direct/bilan");
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.titre-page', '1 – 1');
        self::assertStringContainsString('PETIT Tom', $crawler->filter('.stats-direct')->text());
        self::assertCount(18, $crawler->filter('.carte-chaleur .zone'));

        // Buts reportés sur la feuille : 43e minute pour Tom, 60e pour l'adversaire, score 1 – 1.
        $client->submit($crawler->selectButton('Reporter les buts sur la feuille de match')->form());
        self::assertResponseRedirects("/matchs/$matchId");
        $em->clear();
        $match = $em->find(Rencontre::class, $matchId);
        self::assertSame([1, 1], [$match->getButsDomicile(), $match->getButsExterieur()]);
        $buts = $match->getButs()->toArray();
        self::assertCount(2, $buts);
        self::assertSame($tomId, $buts[0]->getButeur()?->getId());
    }
}
