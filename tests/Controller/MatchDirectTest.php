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

    /** Actions de la carte du terrain du bilan (données passées à la page). */
    private function carte(\Symfony\Component\DomCrawler\Crawler $page): array
    {
        return json_decode($page->filter('[data-controller="carte-match"]')->attr('data-carte-match-donnees-value'), true)['actions'];
    }

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

    /**
     * Coupe : prolongations et tirs au but notés en direct. La séance donne le score des tirs au but du match
     * (côté domicile / extérieur), sans compter dans le score, les buts ni les statistiques ; le match reste un nul.
     */
    public function testCoupeTirsAuBut(): void
    {
        $client = static::createClient();
        $id = bin2hex(random_bytes(4));
        $admin = (new Utilisateur())->setEmail("admin-$id@exemple.test")->setNom('Test')->setPrenom('Admin');
        $club = (new Club())->setNom("FC Coupe $id");
        static::getContainer()->get(CreationClub::class)->creer($club, $admin, 'motdepasse-admin');
        $this->clubId = $club->getId();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $u15 = $em->getRepository(Categorie::class)->findOneBy(['club' => $club, 'nom' => 'U15']);
        $tom = (new Joueur())->setClub($club)->setCategorie($u15)->setNom('Petit')->setPrenom('Tom');
        // Nous jouons à l'extérieur : la séance est rangée côté extérieur pour nous.
        $match = (new Rencontre())->setClub($club)->setCategorie($u15)->setDate(new \DateTimeImmutable('today'))
            ->setDomicile('AS ADVERSE')->setExterieur("FC COUPE $id")->setCompetition('Coupe Gambardella')->setEgalite('prolongations');
        foreach ([$tom, $match] as $e) {
            $em->persist($e);
        }
        $em->flush();
        [$matchId, $tomId] = [$match->getId(), $tom->getId()];
        $client->loginUser($admin);

        $crawler = $client->request('GET', "/matchs/$matchId/live");
        self::assertResponseIsSuccessful();
        $config = json_decode($crawler->filter('[data-controller="live"]')->attr('data-live-config-value'), true);
        self::assertTrue($config['coupe']);
        self::assertSame('prolongations', $config['egalite']);

        $envoyer = fn (array $donnees) => $client->request('POST', "/matchs/$matchId/direct/enregistrer", [], [], ['CONTENT_TYPE' => 'application/json'],
            json_encode($donnees + ['jeton' => $config['jeton']]));
        // 1 – 1 après prolongations (but de Tom à la 95e, en 1re prolongation), puis tirs au but : 2 – 1 pour nous.
        $tir = fn (string $uuid, string $type, ?int $joueur, bool $reussi, int $ordre) => ['uuid' => $uuid, 'joueur' => $joueur, 'type' => $type, 'periode' => 5, 'minute' => 106, 'details' => ['reussi' => $reussi, 'ordre' => $ordre, 'pirate' => 1]];
        $envoyer(['actions' => [
            ['uuid' => 'a1111111-1111-4111-8111-111111111111', 'joueur' => null, 'type' => 'BA', 'periode' => 1, 'minute' => 20, 'details' => ['horsCarte' => true]],
            ['uuid' => 'a2222222-2222-4222-8222-222222222222', 'joueur' => $tomId, 'type' => 'B', 'periode' => 3, 'minute' => 95, 'details' => ['geste' => 'tir', 'horsCarte' => true]],
            $tir('b1111111-1111-4111-8111-111111111111', 'TB', $tomId, true, 1),
            $tir('b2222222-2222-4222-8222-222222222222', 'ATB', null, false, 2),
            $tir('b3333333-3333-4333-8333-333333333333', 'TB', $tomId, true, 3),
            $tir('b4444444-4444-4444-8444-444444444444', 'ATB', null, true, 4),
        ], 'chrono' => ['periode' => 5, 'enCours' => false, 'cumul' => 0, 'fini' => true, 'premier' => 'nous', 'maj' => 10]]);
        self::assertResponseIsSuccessful();
        $retour = json_decode($client->getResponse()->getContent(), true);
        self::assertSame(5, $retour['chrono']['periode'], 'période des tirs au but gardée');
        self::assertSame('nous', $retour['chrono']['premier']);

        $em->clear();
        $match = $em->find(Rencontre::class, $matchId);
        self::assertSame([1, 1], [$match->getButsPour(), $match->getButsContre()], 'tirs au but hors du score');
        self::assertSame([1, 2], [$match->getTabDomicile(), $match->getTabExterieur()], 'séance côté domicile / extérieur');
        self::assertSame('N', $match->getResultat(), 'un nul dans le bilan');
        self::assertTrue($match->getQualifie());
        $tirTom = $em->getRepository(ActionMatch::class)->findOneBy(['uuid' => 'b1111111-1111-4111-8111-111111111111']);
        self::assertEquals(['reussi' => true, 'ordre' => 1], $tirTom->getDetails(), 'marqué, 1er tir, champ inconnu écarté');

        // Bilan : score avec la séance, tirs au but à part, pas dans les statistiques de Tom (1 but, pas 3).
        $crawler = $client->request('GET', "/matchs/$matchId/direct/bilan");
        self::assertSelectorTextContains('.titre-page', '1 – 1 (2 – 1 aux tirs au but, qualifié)');
        self::assertStringContainsString('Tirs au but : 2 – 1', implode(' | ', $crawler->filter('section h2')->each(fn ($h) => $h->text())));
        self::assertStringContainsString('Tom', $crawler->filter('.tab-liste')->first()->text(), 'tireur nommé');
        self::assertStringContainsString('Qualifié aux tirs au but', $crawler->filter('.tab-issue')->text());
        self::assertSame([], array_values(array_filter($this->carte($crawler), fn ($a) => \in_array($a['type'], ['TB', 'ATB'], true))), 'pas sur la carte');

        // Un tir supprimé : séance recalculée.
        $envoyer(['supprimes' => ['b3333333-3333-4333-8333-333333333333']]);
        $em->clear();
        self::assertSame([1, 1], [$em->find(Rencontre::class, $matchId)->getTabDomicile(), $em->find(Rencontre::class, $matchId)->getTabExterieur()]);

        // Liste des matchs : le score avec la séance.
        $client->request('GET', '/matchs');
        self::assertSelectorTextContains('.score-tab', '(1 – 1 aux tirs au but)');
    }

    /**
     * Buts sur coups de pied arrêtés : corner (tiré par le passeur), coup franc, penalty notés en direct, reportés sur la
     * feuille (« Situation », phase CPA), analysés dans le bilan et signalés sur la carte.
     */
    public function testButsSurCoupsDePiedArretes(): void
    {
        $client = static::createClient();
        $id = bin2hex(random_bytes(4));
        $admin = (new Utilisateur())->setEmail("admin-$id@exemple.test")->setNom('Test')->setPrenom('Admin');
        $club = (new Club())->setNom("FC CPA $id");
        static::getContainer()->get(CreationClub::class)->creer($club, $admin, 'motdepasse-admin');
        $this->clubId = $club->getId();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $u15 = $em->getRepository(Categorie::class)->findOneBy(['club' => $club, 'nom' => 'U15']);
        $tom = (new Joueur())->setClub($club)->setCategorie($u15)->setNom('Petit')->setPrenom('Tom');
        $leo = (new Joueur())->setClub($club)->setCategorie($u15)->setNom('Grand')->setPrenom('Léo');
        $match = (new Rencontre())->setClub($club)->setCategorie($u15)->setDate(new \DateTimeImmutable('today'))
            ->setDomicile("FC CPA $id")->setExterieur('AS ADVERSE')->setCompetition('Championnat');
        foreach ([$tom, $leo, $match] as $e) {
            $em->persist($e);
        }
        $em->flush();
        [$matchId, $tomId, $leoId] = [$match->getId(), $tom->getId(), $leo->getId()];
        $client->loginUser($admin);
        $crawler = $client->request('GET', "/matchs/$matchId/live");
        $config = json_decode($crawler->filter('[data-controller="live"]')->attr('data-live-config-value'), true);

        $client->request('POST', "/matchs/$matchId/direct/enregistrer", [], [], ['CONTENT_TYPE' => 'application/json'], json_encode(['jeton' => $config['jeton'], 'actions' => [
            ['uuid' => 'c1111111-1111-4111-8111-111111111111', 'joueur' => null, 'type' => 'CO', 'periode' => 1, 'minute' => 10, 'zx' => 5, 'zy' => 0, 'details' => ['point' => [105, 0]]],
            ['uuid' => 'c2222222-2222-4222-8222-222222222222', 'joueur' => null, 'type' => 'CO', 'periode' => 1, 'minute' => 12, 'zx' => 5, 'zy' => 0, 'details' => ['point' => [105, 0]]],
            // Tête de Tom sur un corner tiré par Léo, venu de la gauche.
            ['uuid' => 'd1111111-1111-4111-8111-111111111111', 'joueur' => $tomId, 'type' => 'B', 'periode' => 1, 'minute' => 12, 'zx' => 5, 'zy' => 0,
                'details' => ['depart' => [99, 20], 'arrivee' => [105, 33], 'geste' => 'tete', 'passeur' => $leoId, 'situation' => 'corner']],
            ['uuid' => 'd2222222-2222-4222-8222-222222222222', 'joueur' => $tomId, 'type' => 'B', 'periode' => 2, 'minute' => 60, 'details' => ['geste' => 'tir', 'situation' => 'cfd', 'horsCarte' => true]],
            // Penalty adverse, pas placé : tiré du point de penalty.
            ['uuid' => 'd3333333-3333-4333-8333-333333333333', 'joueur' => null, 'type' => 'BA', 'periode' => 2, 'minute' => 70, 'details' => ['geste' => 'tir', 'situation' => 'penalty', 'horsCarte' => true]],
            ['uuid' => 'd4444444-4444-4444-8444-444444444444', 'joueur' => $tomId, 'type' => 'B', 'periode' => 2, 'minute' => 80, 'details' => ['geste' => 'tir', 'situation' => 'pirate', 'horsCarte' => true]],
            // Coups francs obtenus (sans joueur, comme un corner), placés ; un de l'adversaire.
            ['uuid' => 'e1111111-1111-4111-8111-111111111111', 'joueur' => null, 'type' => 'CF', 'periode' => 2, 'minute' => 84, 'zx' => 4, 'zy' => 0, 'details' => ['point' => [80, 15]]],
            ['uuid' => 'e2222222-2222-4222-8222-222222222222', 'joueur' => null, 'type' => 'CF', 'periode' => 2, 'minute' => 59, 'zx' => 3, 'zy' => 1, 'details' => ['point' => [78, 30]]],
            ['uuid' => 'e3333333-3333-4333-8333-333333333333', 'joueur' => null, 'type' => 'CFA', 'periode' => 1, 'minute' => 30, 'zx' => 1, 'zy' => 1, 'details' => ['point' => [25, 34]]],
            // Tête de Léo sur un coup franc indirect tiré par Tom depuis la droite.
            ['uuid' => 'd5555555-5555-4555-8555-555555555555', 'joueur' => $leoId, 'type' => 'B', 'periode' => 2, 'minute' => 75, 'zx' => 5, 'zy' => 1,
                'details' => ['depart' => [99, 36], 'arrivee' => [105, 32], 'geste' => 'tete', 'passeur' => $tomId, 'situation' => 'cfi', 'coupFranc' => [80, 15]]],
        ]]));
        self::assertResponseIsSuccessful();

        $em->clear();
        $actions = $em->getRepository(ActionMatch::class);
        self::assertSame('corner', $actions->findOneBy(['uuid' => 'd1111111-1111-4111-8111-111111111111'])->getDetails()['situation']);
        self::assertEquals(['depart' => [11.0, 34.0], 'arrivee' => [0.0, 34.0], 'geste' => 'tir', 'situation' => 'penalty'],
            $actions->findOneBy(['uuid' => 'd3333333-3333-4333-8333-333333333333'])->getDetails(), 'penalty au point de penalty');
        self::assertArrayNotHasKey('situation', $actions->findOneBy(['uuid' => 'd4444444-4444-4444-8444-444444444444'])->getDetails(), 'situation inconnue écartée');

        // Feuille de match : « Situation » et phase « Coup de pied arrêté ».
        $buts = [];
        foreach ($em->find(Rencontre::class, $matchId)->getButs() as $b) {
            $buts[$b->getMinute()] = [$b->getConditions(), $b->getPhase()];
        }
        ksort($buts);
        self::assertSame([12 => ['Corner gauche', 'CPA'], 60 => ['CF direct', 'CPA'], 70 => ['Pénalty', 'CPA'], 75 => ['CF indirect', 'CPA'], 80 => [null, null]], $buts);

        // Bilan : d'où viennent les buts, corners transformés, tireur décisif, carte.
        $crawler = $client->request('GET', "/matchs/$matchId/direct/bilan");
        self::assertResponseIsSuccessful();
        $lignes = $crawler->filter('.origines-buts tbody tr')->each(fn ($tr) => $tr->filter('th, td')->each(fn ($c) => $c->text()));
        self::assertSame([
            ['Action de jeu', '1', '–'], ['Corner', '1', '–'], ['Corner direct', '–', '–'], ['Coup franc direct', '1', '–'], ['Coup franc indirect', '1', '–'], ['Penalty', '–', '1'],
            ['Sur coups de pied arrêtés', '3', '1'], ['Corners transformés', '1 sur 2 (50 %)', '0 sur 0'],
            ['Coups francs transformés', '2 sur 2 (100 %)', '0 sur 1 (0 %)'],
        ], $lignes);
        self::assertStringContainsString('Tom PETIT (1 coup franc)', $crawler->filter('.origines-buts')->ancestors()->filter('section')->text());
        self::assertStringContainsString('Léo GRAND (1 corner)', $crawler->filter('.origines-buts')->ancestors()->filter('section')->text());
        $corner = array_values(array_filter($this->carte($crawler), fn ($a) => 'B' === $a['type'] && 12 === $a['minute']))[0];
        self::assertSame('corner', $corner['details']['situation']);
        self::assertStringContainsString('sur corner de Léo GRAND', $corner['libelle']);
        self::assertStringContainsString('Corner tiré par Léo GRAND', $crawler->filter('.buts-liste')->first()->text());

        $cf = array_values(array_filter($this->carte($crawler), fn ($a) => 'B' === $a['type'] && 75 === $a['minute']))[0];
        self::assertEquals([80, 15], $cf['details']['coupFranc'], 'endroit du coup franc indirect sur la carte');
        self::assertCount(2, array_filter($this->carte($crawler), fn ($a) => 'CF' === $a['type']), 'coups francs obtenus sur la carte');
        self::assertStringContainsString('2 – 1', $crawler->filter('.tuile')->reduce(fn ($t) => str_contains($t->text(), 'coups francs'))->text());

        // Situation écrite à la main sur la feuille → code du direct.
        self::assertSame(['corner', 'cod', 'cfi', 'cfd', 'penalty', null], array_map([ActionMatch::class, 'situationDeConditions'], ['Corner droit', 'Corner direct', 'CF indirect', 'Coup franc direct', 'Pénalty', 'Tir croisé']));
        // Corner direct : pas de passeur (le buteur a tiré le corner).
        $direct = (new ActionMatch($em->find(Rencontre::class, $matchId), 'test'))->setType('B')->setDetails(['situation' => 'cod', 'passeur' => $leoId, 'geste' => 'tir']);
        self::assertSame(['geste' => 'tir', 'situation' => 'cod'], $direct->getDetails());
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
        $envoyer(['actions' => $actions, 'infos' => ['meteo' => 'Pluie', 'terrain' => 'Synthétique', 'vent' => 'Fort', 'temperature' => '-3']]);
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
        self::assertSame(['Fort', '-3'], [$em->find(Rencontre::class, $matchId)->getVent(), $em->find(Rencontre::class, $matchId)->getTemperature()]);

        // Endroit précis touché sur le terrain : point gardé (mètres dans le sens de l'attaque), dessiné sur la carte.
        $envoyer(['actions' => [['uuid' => '88888888-8888-4888-8888-888888888888', 'joueur' => $tomId, 'type' => 'C', 'periode' => 1, 'minute' => 20,
            'zx' => 5, 'zy' => 2, 'details' => ['point' => [97.3, 61.8], 'pirate' => 'x']]]]);
        $em->clear();
        self::assertSame(['point' => [97.3, 61.8]], $em->getRepository(ActionMatch::class)->findOneBy(['uuid' => '88888888-8888-4888-8888-888888888888'])->getDetails());
        $carte = $this->carte($client->request('GET', "/matchs/$matchId/direct/bilan"));
        self::assertContains([97.3, 61.8], array_map(fn ($a) => $a['details']['point'], array_filter($carte, fn ($a) => 'C' === $a['type'])));
        $envoyer(['supprimes' => ['88888888-8888-4888-8888-888888888888']]);

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

        // La feuille de match a suivi tout de suite : le but de Tom (passe de Léo) et le but adverse, score 1 – 1 ;
        // buteur et passeur ajoutés à la feuille.
        $match = $em->find(Rencontre::class, $matchId);
        self::assertSame([1, 1], [$match->getButsDomicile(), $match->getButsExterieur()]);
        $buts = $match->getButs()->toArray();
        self::assertCount(2, $buts);
        self::assertSame([$tomId, 'Tête', $leoId], [$buts[0]->getButeur()?->getId(), $buts[0]->getGeste(), $buts[0]->getPasseur()?->getId()]);
        self::assertEquals(['depart' => [96.4, 30.2], 'arrivee' => [105, 36.1]], $buts[0]->getTrace(), 'tracé du but repris sur la feuille');
        self::assertCount(2, $match->getParticipations());

        // Numéros de maillot donnés sur la tablette : sur la feuille pour les joueurs qui y sont, renvoyés à toutes
        // les tablettes ; un numéro hors 1-99 efface.
        $client->request('POST', "/matchs/$matchId/direct/enregistrer", [], [], ['CONTENT_TYPE' => 'application/json'],
            json_encode(['jeton' => $config['jeton'], 'numeros' => [$tomId => 9, $leoId => 150]]));
        $numeros = array_column(json_decode($client->getResponse()->getContent(), true)['joueurs'], 'numero', 'id');
        self::assertSame(9, $numeros[$tomId]);
        self::assertNull($numeros[$leoId]);

        // Titulaires : ceux de la feuille de match (Tom et Léo, ajoutés avec les premiers numéros libres 1 et 2 :
        // titulaires d'office) ; choisis sur la tablette, ils sont communs aux tablettes et cochés sur la feuille.
        self::assertEqualsCanonicalizing([$tomId, $leoId], $config['titulaires']);
        self::assertSame(11, $config['maxTitulaires'], 'U15 sans système choisi : foot à 11');
        $client->request('POST', "/matchs/$matchId/direct/enregistrer", [], [], ['CONTENT_TYPE' => 'application/json'],
            json_encode(['jeton' => $config['jeton'], 'titulaires' => [$tomId, 999999]]));
        self::assertSame([$tomId], json_decode($client->getResponse()->getContent(), true)['titulaires']);
        $em->clear();
        self::assertSame([$tomId], $em->find(Rencontre::class, $matchId)->getTitulairesDirect());
        $cases = [];
        foreach ($em->find(Rencontre::class, $matchId)->getParticipations() as $p) {
            $cases[$p->getJoueur()->getId()] = $p->isTitulaire();
        }
        self::assertSame([true, false], [$cases[$tomId], $cases[$leoId]], 'feuille de match : Tom titulaire, Léo remplaçant');

        // Jeton manquant : refusé.
        $client->request('POST', "/matchs/$matchId/direct/enregistrer", [], [], ['CONTENT_TYPE' => 'application/json'], json_encode(['actions' => $actions]));
        self::assertResponseStatusCodeSame(403);

        // Cartons : Tom prend un jaune à la 30e ; l'adversaire aussi.
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
        self::assertCount(2, array_filter($this->carte($crawler), fn ($a) => $a['eux']));
        $surTom = $client->request('GET', "/matchs/$matchId/direct/bilan?joueur=$tomId");
        self::assertSame($tomId, json_decode($surTom->filter('[data-controller="carte-match"]')->attr('data-carte-match-donnees-value'), true)['joueur']);

        // Feuille de match déjà à jour (carton jaune de Tom à la 30e) : rien à signaler ; le rattrapage ne change rien.
        $client->request('GET', "/matchs/$matchId/modifier");
        self::assertSelectorNotExists('.bandeau-direct');
        $em->clear();
        $match = $em->find(Rencontre::class, $matchId);
        self::assertNull($match->getButs()->toArray()[1]->getTrace(), 'but adverse sans tracé');
        $pageDirect = $client->request('GET', "/matchs/$matchId/direct");
        $client->submit($pageDirect->selectButton('Reporter sur la feuille de match')->form());
        self::assertResponseRedirects("/matchs/$matchId/modifier");
        $messages = implode(' | ', $client->followRedirect()->filter('.flash')->each(fn ($f) => trim($f->text())));
        self::assertStringContainsString('déjà à jour', $messages);

        // Page du match : schéma du but placé ; feuille de match : le tracé se modifie (champ caché de la ligne du but).
        $crawler = $client->request('GET', "/matchs/$matchId");
        self::assertStringContainsString('Buts marqués', $crawler->filter('.buts-match')->text());
        $crawler = $client->request('GET', "/matchs/$matchId/modifier");
        $formulaire = $crawler->filter('form[name="rencontre"]')->form();
        $champs = array_values(array_filter(array_keys($formulaire->getValues()), fn ($n) => str_ends_with($n, '[traceJson]')));
        self::assertCount(2, $champs);
        $formulaire[$champs[1]] = json_encode(['depart' => [8, 30], 'arrivee' => [0, 33.5]]);
        $client->submit($formulaire);
        self::assertResponseRedirects();
        $em->clear();
        $traces = array_map(fn ($b) => $b->getTrace(), $em->find(Rencontre::class, $matchId)->getButs()->toArray());
        self::assertContainsEquals(['depart' => [8.0, 30.0], 'arrivee' => [0.0, 33.5]], $traces, 'tracé du but adverse enregistré depuis la feuille');
        // Les buts du direct suivent la feuille : le but adverse noté en direct (même but) reçoit la place dessinée sur la feuille.
        $buts = $em->getRepository(ActionMatch::class)->findBy(['rencontre' => $matchId, 'type' => ['B', 'BA']]);
        self::assertCount(2, $buts, 'mêmes buts que la feuille, sans doublon');
        $adverseDirect = $em->getRepository(ActionMatch::class)->findOneBy(['uuid' => '44444444-4444-4444-8444-444444444444']);
        self::assertEquals(['depart' => [8.0, 30.0], 'arrivee' => [0.0, 33.5]], $adverseDirect->getDetails());

        // Statistiques du match : la feuille fait foi pour les buts (score, schémas, carte du terrain), même pour un but
        // placé seulement sur la feuille ; ses cartons comptent.
        $crawler = $client->request('GET', "/matchs/$matchId/direct/bilan");
        self::assertSelectorTextContains('.titre-page', '1 – 1');
        self::assertStringContainsString('Buts encaissés (1)', $crawler->filter('.buts-schemas')->text());
        $butsAdverses = array_values(array_filter($this->carte($crawler), fn ($a) => 'BA' === $a['type']));
        self::assertCount(1, $butsAdverses, 'but adverse placé sur la feuille : sur la carte');
        self::assertEquals([8.0, 30.0], $butsAdverses[0]['details']['point'], 'à l\'endroit du tir dessiné sur la feuille');
        self::assertStringContainsString('(feuille de match)', $crawler->filter('.journal')->text());
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
        // But adverse de la feuille pas placé sur le terrain : la zone notée en direct reste sur la carte.
        $butsAdverses = array_values(array_filter($this->carte($client->request('GET', "/matchs/$matchId/direct/bilan")), fn ($a) => 'BA' === $a['type']));
        self::assertCount(1, $butsAdverses);
        self::assertNotNull($butsAdverses[0]['zx'], 'zone notée en direct');
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

        // Changement : Tom sort sur blessure, Léo entre à la 50e ; refusés : même joueur qui sort et entre, entrant hors du match.
        $envoyer(['jeton' => $config['jeton'], 'actions' => [
            ['uuid' => 'cccccccc-cccc-4ccc-8ccc-cccccccccccc', 'joueur' => $tomId, 'type' => 'RP', 'periode' => 2, 'minute' => 50, 'zx' => 0, 'zy' => 1, 'details' => ['entrant' => $leoId, 'motif' => 'Blessure']],
            ['uuid' => 'dddddddd-dddd-4ddd-8ddd-dddddddddddd', 'joueur' => $tomId, 'type' => 'RP', 'periode' => 2, 'minute' => 52, 'zx' => 0, 'zy' => 1, 'details' => ['entrant' => $tomId]],
            ['uuid' => 'eeeeeeee-eeee-4eee-8eee-eeeeeeeeeeee', 'joueur' => $tomId, 'type' => 'RP', 'periode' => 2, 'minute' => 53, 'zx' => 0, 'zy' => 1, 'details' => ['entrant' => 999999]],
            // Léo prend un carton blanc à la 20e (exclusion temporaire), puis sort à la 70e sans être remplacé (plus de remplaçant).
            ['uuid' => 'abababab-abab-4bab-8bab-abababababab', 'joueur' => $leoId, 'type' => 'EX', 'periode' => 1, 'minute' => 20, 'zx' => 0, 'zy' => 1],
            ['uuid' => 'ffffffff-ffff-4fff-8fff-ffffffffffff', 'joueur' => $leoId, 'type' => 'RP', 'periode' => 2, 'minute' => 70, 'zx' => 0, 'zy' => 1, 'details' => ['motif' => 'Choix']],
        ]]);
        $em->clear();
        $changement = $em->getRepository(ActionMatch::class)->findBy(['rencontre' => $matchId, 'type' => 'RP'], ['minute' => 'ASC']);
        self::assertCount(2, $changement);
        self::assertEquals(['entrant' => $leoId, 'motif' => 'Blessure'], $changement[0]->getDetails());
        self::assertSame(['motif' => 'Choix'], $changement[1]->getDetails());
        $crawler = $client->request('GET', "/matchs/$matchId/direct/bilan");
        self::assertStringContainsString('Changements (3)', $crawler->filter('.liste-changements')->ancestors()->first()->text());
        self::assertStringContainsString('sans remplaçant', $crawler->filter('.liste-changements')->text());
        self::assertStringContainsString('carton blanc', $crawler->filter('.liste-changements')->text());

        // Feuille de match à jour toute seule : carton blanc (« BL ») et remplacements, une seule fois (envoi rejoué).
        $client->request('GET', "/matchs/$matchId/modifier");
        self::assertSelectorNotExists('.bandeau-direct');
        $em->clear();
        $remplacements = $em->find(Rencontre::class, $matchId)->getRemplacements()->toArray();
        usort($remplacements, fn ($a, $b) => $a->getMinute() <=> $b->getMinute());
        self::assertCount(2, $remplacements, 'pas de doublon au second report');
        self::assertSame([$tomId, $leoId, 50, 'Blessure'], [$remplacements[0]->getSortant()->getId(), $remplacements[0]->getEntrant()->getId(), $remplacements[0]->getMinute(), $remplacements[0]->getMotif()]);
        self::assertSame([$leoId, null, 70], [$remplacements[1]->getSortant()->getId(), $remplacements[1]->getEntrant(), $remplacements[1]->getMinute()]);
        $temps = [];
        foreach ($em->find(Rencontre::class, $matchId)->getParticipations() as $p) {
            $temps[$p->getJoueur()->getId()] = $p->getTempsJeu();
            if ($p->getJoueur()->getId() === $leoId) {
                self::assertSame(['BL', '20'], [$p->getCarton(), $p->getMinuteCarton()], 'carton blanc reporté sur la feuille');
            }
        }
        // Temps de jeu recalculés au report (pour les statistiques) : Tom titulaire sorti à la 50e ;
        // Léo entré à la 50e, sorti à la 70e, carton blanc (10 minutes en moins).
        self::assertSame([50, 10], [$temps[$tomId], $temps[$leoId]]);
        $client->request('GET', "/matchs/$matchId/modifier");
        self::assertSelectorNotExists('.bandeau-direct');

        // Feuille restée ouverte pendant qu'une tablette note un carton : l'enregistrement est refusé (rien d'écrasé).
        $ouverte = $client->request('GET', "/matchs/$matchId/modifier")->filter('form[name="rencontre"]')->form();
        $envoyer(['jeton' => $config['jeton'], 'actions' => [
            ['uuid' => 'acacacac-acac-4cac-8cac-acacacacacac', 'joueur' => $tomId, 'type' => 'CJ', 'periode' => 2, 'minute' => 48, 'zx' => 2, 'zy' => 1],
        ]]);
        $client->submit($ouverte);
        self::assertResponseRedirects("/matchs/$matchId/modifier");
        $client->followRedirect();
        self::assertSelectorTextContains('.flash-erreur', 'notés en direct');
        $em->clear();
        $tom = array_values(array_filter($em->find(Rencontre::class, $matchId)->getParticipations()->toArray(), fn ($p) => $p->getJoueur()->getId() === $tomId))[0];
        self::assertSame(['J+R', '48'], [$tom->getCarton(), $tom->getMinuteCarton()], 'deux jaunes notés en direct : jaune puis rouge');

        // Feuille → direct : le carton de Tom corrigé sur la feuille (un seul jaune, à la 30e) l'est aussi dans le direct.
        $crawler = $client->request('GET', "/matchs/$matchId/modifier");
        $formulaire = $crawler->filter('form[name="rencontre"]')->form();
        foreach ($formulaire->getValues() as $nom => $valeur) {
            if (str_ends_with($nom, '[carton]') && 'J+R' === $valeur) {
                $formulaire[$nom] = 'JJ';
                $formulaire[str_replace('[carton]', '[minuteCarton]', $nom)] = '30';
            }
        }
        $client->submit($formulaire);
        self::assertResponseRedirects("/matchs/$matchId");
        $em->clear();
        $cartonsTom = array_map(fn (ActionMatch $a) => [$a->getType(), $a->getMinute()],
            $em->getRepository(ActionMatch::class)->findBy(['rencontre' => $matchId, 'joueur' => $tomId, 'type' => ['CJ', 'CR']]));
        self::assertSame([['CJ', 30]], $cartonsTom);
    }
}
