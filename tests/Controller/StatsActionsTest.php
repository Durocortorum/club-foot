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
use App\Service\StatsActions;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Actions notées en direct additionnées sur la saison : onglet « Actions » des statistiques (totaux, moyennes par
 * 90 minutes, comparaison à l'équipe, quarts d'heure) et fiche du joueur (par saison, match par match).
 */
class StatsActionsTest extends WebTestCase
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

    public function testActionsSurLaSaison(): void
    {
        $client = static::createClient();
        $id = bin2hex(random_bytes(4));
        $admin = (new Utilisateur())->setEmail("admin-$id@exemple.test")->setNom('Test')->setPrenom('Admin');
        $club = (new Club())->setNom("FC Actions $id");
        static::getContainer()->get(CreationClub::class)->creer($club, $admin, 'motdepasse-admin');
        $this->clubId = $club->getId();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $u15 = $em->getRepository(Categorie::class)->findOneBy(['club' => $club, 'nom' => 'U15']);
        $tom = (new Joueur())->setClub($club)->setCategorie($u15)->setNom('Petit')->setPrenom('Tom');
        $leo = (new Joueur())->setClub($club)->setCategorie($u15)->setNom('Durand')->setPrenom('Léo');
        $em->persist($tom);
        $em->persist($leo);

        // Trois matchs : deux suivis en direct, un sans aucune action notée (non compté).
        $matchs = [];
        foreach (['-2 days', '-1 day', 'today'] as $i => $quand) {
            $m = (new Rencontre())->setClub($club)->setCategorie($u15)->setDate(new \DateTimeImmutable($quand))
                ->setDomicile("FC ACTIONS $id")->setExterieur('AS ADVERSE ' . $i);
            // Léo n'est pas sur la feuille du deuxième match (une action notée quand même) : 80 minutes en tout.
            foreach (1 === $i ? [$tom] : [$tom, $leo] as $j) {
                $m->addParticipation((new Participation())->setClub($club)->setJoueur($j)->setTitulaire(true)->setTempsJeu(80));
            }
            $em->persist($m);
            $matchs[] = $m;
        }
        $n = 0;
        $noter = function (Rencontre $m, ?Joueur $j, string $type, int $minute) use ($club, $em, &$n) {
            $em->persist((new ActionMatch($m, \sprintf('%08d-0000-4000-8000-000000000000', ++$n)))->setClub($club)->setType($type)->setJoueur($j)->placer(1, $minute, 2, 1));
        };
        // Match 1 : Tom 3 bonnes passes, 1 mauvaise ; Léo un duel gagné, un perdu ; une frappe adverse cadrée.
        foreach (['BP', 'BP', 'BP', 'MP'] as $t) {
            $noter($matchs[0], $tom, $t, 5);
        }
        $noter($matchs[0], $leo, 'DG', 10);
        $noter($matchs[0], $leo, 'DP', 20);
        $noter($matchs[0], null, 'AC', 30);
        // Match 2 : Tom 1 bonne passe, 1 mauvaise, une frappe cadrée.
        $noter($matchs[1], $tom, 'BP', 3);
        $noter($matchs[1], $tom, 'MP', 4);
        $noter($matchs[1], $tom, 'FC', 70);
        $noter($matchs[1], $leo, 'MP', 6);
        $em->flush();
        [$saison, $catId, $tomId] = [$matchs[0]->getSaison(), $u15->getId(), $tom->getId()];
        $em->clear();

        // Service : deux matchs suivis ; Tom 4 / 2 passes en 160 minutes.
        $stats = static::getContainer()->get(StatsActions::class)->saison($em->getRepository(Rencontre::class)->findBy(['club' => $this->clubId]));
        self::assertSame(2, $stats['matchs']);
        $lignes = array_column($stats['joueurs'], null, 'id');
        self::assertSame([4, 2, 160, 2], [$lignes[$tomId]['a']['BP'], $lignes[$tomId]['a']['MP'], $lignes[$tomId]['minutes'], $lignes[$tomId]['matchs']]);
        self::assertSame([4, 3, 1, 1], [$stats['equipe']['BP'], $stats['equipe']['MP'], $stats['equipe']['DG'], $stats['equipe']['AC']]);

        $client->loginUser($admin);
        $crawler = $client->request('GET', '/statistiques', ['vue' => 'actions', 'saison' => $saison, 'categorie' => $catId, 'tri' => 'passes']);
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.tuile', '2matchs suivis en direct');
        $premiere = $crawler->filter('.stats-direct tbody tr')->first()->text();
        self::assertStringContainsString('PETIT Tom', $premiere, 'trié par passes réussies');
        self::assertStringContainsString('4 / 2', $premiere);
        self::assertStringContainsString('67 %', $premiere);
        // Équipe : 4 / 3 passes (57 %) ; Tom (67 %) fait mieux (▲), Léo (0 %) moins bien (▼) ; duels de Léo : comme l'équipe (=).
        self::assertSelectorTextContains('.stats-direct tfoot', '4 / 3');
        self::assertSame('▲', $crawler->filter('.stats-direct tbody tr')->first()->filter('.ecart-equipe')->first()->text());
        self::assertSame(['▼', '='], $crawler->filter('.stats-direct tbody tr')->eq(1)->filter('.ecart-equipe')->each(fn ($e) => $e->text()));
        // Tom : frappes cadrées 1 / 1.
        self::assertStringContainsString('1 / 1', $premiere);

        // Par 90 minutes : Tom 4 passes en 160 minutes = 2,3 ; Léo (80 minutes) trop peu de temps de jeu.
        $crawler = $client->request('GET', '/statistiques', ['vue' => 'actions', 'saison' => $saison, 'categorie' => $catId, 'tri' => 'passes', 'mode' => 'par90']);
        self::assertStringContainsString('2,3 / 1,1', $crawler->filter('.stats-direct tbody tr')->first()->text());
        self::assertStringContainsString('– / –', $crawler->filter('.stats-direct tbody tr')->eq(1)->text());

        // Fiche de Tom : la saison et ses deux matchs suivis (pas le troisième).
        $crawler = $client->request('GET', "/joueurs/$tomId");
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('#actions tbody tr', '4 / 2');
        self::assertCount(2, $crawler->filter('#actions [data-periode-target="ligne"]'));
    }
}
