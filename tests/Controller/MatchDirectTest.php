<?php

namespace App\Tests\Controller;

use App\Entity\ActionMatch;
use App\Entity\Categorie;
use App\Entity\Club;
use App\Entity\Joueur;
use App\Entity\Participation;
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
            foreach (['action_match', 'but', 'participation', 'rencontre', 'utilisateur', 'joueur', 'categorie'] as $table) {
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
            ['uuid' => '33333333-3333-4333-8333-333333333333', 'joueur' => $tomId, 'type' => 'B', 'periode' => 1, 'minute' => 43, 'zx' => 5, 'zy' => 1,
                'details' => ['depart' => [96.4, 30.2], 'arrivee' => [105, 36.1], 'geste' => 'tete', 'passeur' => $leoId, 'pirate' => 'x']],
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

        // Deuxième tablette (autre compte de l'encadrement) : elle reçoit les actions de la première avec leur auteur,
        // ne peut ni les corriger ni les supprimer, et ses actions s'ajoutent ; le chrono est commun.
        $chrono = ['periode' => 1, 'enCours' => true, 'debut' => 1_700_000_000_000, 'cumul' => 0, 'fini' => false, 'sens' => 1, 'maj' => 1_700_000_000_000];
        $envoyer(['chrono' => $chrono]);
        $collegue = (new Utilisateur())->setEmail("collegue-$id@exemple.test")->setNom('Collègue')->setPrenom('Paul')->setRolesAttribues(['ROLE_ADMIN']);
        $collegue->setClub($em->getReference(Club::class, $this->clubId));
        $em->persist($collegue);
        $em->flush();
        $client->loginUser($collegue);
        $crawler = $client->request('GET', "/matchs/$matchId/direct");
        $config2 = json_decode($crawler->filter('[data-controller="direct"]')->attr('data-direct-config-value'), true);
        self::assertSame(1_700_000_000_000, $config2['chrono']['maj'], 'chrono lancé sur la première tablette');
        self::assertSame('Admin T.', $config2['actions'][0]['par']);
        $client->request('POST', "/matchs/$matchId/direct/enregistrer", [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'jeton' => $config2['jeton'],
            'supprimes' => ['33333333-3333-4333-8333-333333333333'],
            'actions' => [['type' => 'FH'] + $actions[0], ['uuid' => '77777777-7777-4777-8777-777777777777', 'joueur' => $leoId, 'type' => 'DG', 'periode' => 1, 'minute' => 10, 'zx' => 2, 'zy' => 2]],
            'chrono' => ['maj' => 1_600_000_000_000, 'enCours' => false] + $chrono, // plus ancien : ignoré
        ]));
        $reponse = json_decode($client->getResponse()->getContent(), true);
        self::assertSame(4, $reponse['total'], 'but de la première tablette toujours là, duel ajouté');
        self::assertSame(['MP', 'DG', 'B', 'BA'], array_column($reponse['actions'], 'type'), 'la mauvaise passe de la première tablette n\'est pas devenue une frappe');
        self::assertSame('Paul C.', $reponse['actions'][1]['par']);
        self::assertTrue($reponse['chrono']['enCours'], 'un chrono plus ancien ne remplace pas le plus récent');
        $client->loginUser($admin);
        $crawler = $client->request('GET', "/matchs/$matchId/direct");
        $config = json_decode($crawler->filter('[data-controller="direct"]')->attr('data-direct-config-value'), true);
        $em->clear();
        self::assertSame(['MP', 'DG', 'B', 'BA'], array_map(fn (ActionMatch $a) => $a->getType(), $em->getRepository(ActionMatch::class)->findBy(['rencontre' => $matchId], ['minute' => 'ASC'])));

        // Numéros de maillot donnés sur la tablette (pas de feuille de match) : gardés avec le match, renvoyés à toutes
        // les tablettes ; un numéro hors 1-99 efface.
        $client->request('POST', "/matchs/$matchId/direct/enregistrer", [], [], ['CONTENT_TYPE' => 'application/json'],
            json_encode(['jeton' => $config['jeton'], 'numeros' => [$tomId => 23, $leoId => 150]]));
        $numeros = array_column(json_decode($client->getResponse()->getContent(), true)['joueurs'], 'numero', 'id');
        self::assertSame(23, $numeros[$tomId]);
        self::assertNull($numeros[$leoId]);
        $em->clear();
        self::assertSame([$tomId => 23], $em->find(Rencontre::class, $matchId)->getNumerosDirect());

        // Jeton manquant : refusé.
        $client->request('POST', "/matchs/$matchId/direct/enregistrer", [], [], ['CONTENT_TYPE' => 'application/json'], json_encode(['actions' => $actions]));
        self::assertResponseStatusCodeSame(403);

        // Cartons : Tom (sur la feuille de match) prend un jaune à la 30e ; l'adversaire aussi.
        $em->persist((new Participation())->setClub($em->getReference(Club::class, $this->clubId))->setRencontre($em->getReference(Rencontre::class, $matchId))
            ->setJoueur($em->getReference(Joueur::class, $tomId))->setNumero(9));
        $em->flush();
        $envoyer(['jeton' => $config['jeton'], 'actions' => [
            ['uuid' => '88888888-8888-4888-8888-888888888888', 'joueur' => $tomId, 'type' => 'CJ', 'periode' => 1, 'minute' => 30, 'zx' => 3, 'zy' => 1],
            ['uuid' => '99999999-9999-4999-8999-999999999999', 'joueur' => null, 'type' => 'JA', 'periode' => 2, 'minute' => 55, 'zx' => 2, 'zy' => 2],
        ]]);
        self::assertSame(6, json_decode($client->getResponse()->getContent(), true)['total']);

        // Bilan : score, ligne du joueur, carte du terrain (tout ce qu'a fait l'adversaire : son but et son carton).
        $crawler = $client->request('GET', "/matchs/$matchId/direct/bilan");
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.titre-page', '1 – 1');
        self::assertStringContainsString('PETIT Tom', $crawler->filter('.stats-direct')->text());
        self::assertCount(18, $crawler->filter('.carte-chaleur .zone'));
        $adverse = $client->request('GET', "/matchs/$matchId/direct/bilan?type=adverse");
        self::assertSame(2, array_sum(array_map('intval', $adverse->filter('.carte-chaleur .zone')->each(fn ($z) => $z->text()))));

        // La feuille de match signale ce qui a été noté en direct et n'y est pas encore.
        $client->request('GET', "/matchs/$matchId/modifier");
        self::assertSelectorTextContains('.bandeau-direct', '2 buts et 1 carton');

        // Report sur la feuille : buts (43e Tom, 60e adversaire, score 1 – 1) et carton jaune de Tom à la 30e.
        $client->submit($crawler->selectButton('Reporter buts et cartons sur la feuille de match')->form());
        self::assertResponseRedirects("/matchs/$matchId");
        $em->clear();
        $match = $em->find(Rencontre::class, $matchId);
        self::assertSame([1, 1], [$match->getButsDomicile(), $match->getButsExterieur()]);
        $buts = $match->getButs()->toArray();
        self::assertCount(2, $buts);
        self::assertSame($tomId, $buts[0]->getButeur()?->getId());
        self::assertSame('Tête', $buts[0]->getGeste(), 'but de la tête noté en direct');
        self::assertSame($leoId, $buts[0]->getPasseur()?->getId(), 'passeur décisif noté en direct');
        $feuille = [];
        foreach ($match->getParticipations() as $p) {
            $feuille[$p->getJoueur()->getId()] = $p;
        }
        self::assertArrayHasKey($leoId, $feuille, 'passeur ajouté à la feuille de match');
        $feuilleTom = $feuille[$tomId];
        $client->request('GET', "/matchs/$matchId/modifier");
        self::assertSelectorNotExists('.bandeau-direct');
        self::assertSame(['JJ', '30'], [$feuilleTom->getCarton(), $feuilleTom->getMinuteCarton()]);

        // Tracé du but gardé (champ inconnu écarté) ; schéma des buts dans le bilan ; but contre son camp sans buteur.
        $tete = $em->getRepository(ActionMatch::class)->findOneBy(['uuid' => '33333333-3333-4333-8333-333333333333']);
        self::assertEquals(['depart' => [96.4, 30.2], 'arrivee' => [105, 36.1], 'geste' => 'tete', 'passeur' => $leoId], $tete->getDetails());
        $crawler = $client->request('GET', "/matchs/$matchId/direct/bilan");
        self::assertStringContainsString('Buts marqués (1)', $crawler->filter('.buts-schemas')->text());
        $envoyer(['jeton' => $config['jeton'], 'actions' => [
            ['uuid' => 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa', 'joueur' => null, 'type' => 'B', 'periode' => 2, 'minute' => 70, 'zx' => 5, 'zy' => 1,
                'details' => ['depart' => [101, 34], 'arrivee' => [105, 33], 'geste' => 'csc']],
            // Passeur = buteur : passeur écarté.
            ['uuid' => 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb', 'joueur' => $tomId, 'type' => 'B', 'periode' => 2, 'minute' => 75, 'zx' => 5, 'zy' => 1, 'details' => ['geste' => 'tir', 'passeur' => $tomId]],
        ]]);
        $em->clear();
        $csc = $em->getRepository(ActionMatch::class)->findOneBy(['uuid' => 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa']);
        self::assertNotNull($csc, 'but contre son camp accepté sans joueur');
        self::assertSame('csc', $csc->getDetails()['geste']);
        self::assertSame(['geste' => 'tir'], $em->getRepository(ActionMatch::class)->findOneBy(['uuid' => 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb'])->getDetails());
    }
}
