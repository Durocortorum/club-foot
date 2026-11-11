<?php

namespace App\Tests\Controller;

use App\Entity\Categorie;
use App\Entity\Club;
use App\Entity\Rencontre;
use App\Entity\Tournoi;
use App\Entity\Utilisateur;
use App\Service\CreationClub;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Tournoi : fiche, matchs ajoutés depuis la fiche (avec ou sans score), bilan, calendrier, convocation.
 */
class TournoiTest extends WebTestCase
{
    private ?int $clubId = null;

    protected function tearDown(): void
    {
        if ($this->clubId) {
            $connexion = static::getContainer()->get(EntityManagerInterface::class)->getConnection();
            foreach (['convocation', 'rencontre', 'tournoi', 'utilisateur', 'categorie'] as $table) {
                $connexion->executeStatement("DELETE FROM $table WHERE club_id = ?", [$this->clubId]);
            }
            $connexion->executeStatement('DELETE FROM club WHERE id = ?', [$this->clubId]);
        }
        parent::tearDown();
    }

    public function testTournoi(): void
    {
        $client = static::createClient();
        $id = bin2hex(random_bytes(4));
        $admin = (new Utilisateur())->setEmail("admin-$id@exemple.test")->setNom('Test')->setPrenom('Admin');
        $club = (new Club())->setNom("Club Test $id");
        static::getContainer()->get(CreationClub::class)->creer($club, $admin, 'motdepasse-admin');
        $this->clubId = $club->getId();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $u13 = $em->getRepository(Categorie::class)->findOneBy(['club' => $club, 'nom' => 'U13']);
        $jour = new \DateTimeImmutable('first saturday of next month');

        $client->loginUser($admin);
        $crawler = $client->request('GET', '/tournois');
        self::assertResponseIsSuccessful();
        $crawler = $client->click($crawler->selectLink('+ Nouveau tournoi')->link());
        $client->submit($crawler->selectButton('Enregistrer')->form([
            'tournoi[nom]' => 'Tournoi de Pâques', 'tournoi[categorie]' => $u13->getId(), 'tournoi[debut]' => $jour->format('Y-m-d'),
            'tournoi[fin]' => $jour->modify('+1 day')->format('Y-m-d'), 'tournoi[lieu]' => 'Tinqueux', 'tournoi[transport]' => 'Minibus du club',
            'tournoi[coutInscription]' => '30',
        ]));
        $crawler = $client->followRedirect();
        self::assertSelectorTextContains('h1', 'Tournoi de Pâques');
        self::assertStringContainsString('Minibus du club', $crawler->filter('.grille-fiches')->text());

        // Convocation sans match : il faut d'abord un match.
        $client->request('GET', $crawler->selectLink('Convocation')->link()->getUri());
        $crawler = $client->followRedirect();
        self::assertSelectorTextContains('.flash-info', 'Ajoutez d\'abord un match');

        // Deux matchs : un gagné 2-0 le samedi, un à jouer le dimanche.
        $client->submit($crawler->selectButton('Ajouter le match')->form(['adversaire' => 'AS Tinqueux', 'heure' => '10H00', 'pour' => '2', 'contre' => '0']));
        $crawler = $client->followRedirect();
        $client->submit($crawler->selectButton('Ajouter le match')->form(['adversaire' => 'Stade de Reims', 'jour' => $jour->modify('+1 day')->format('Y-m-d'), 'heure' => '11H00']));
        $crawler = $client->followRedirect();
        self::assertStringContainsString('1 victoire, 0 nul, 0 défaite', $crawler->filter('#matchs')->text());
        $tournoi = $em->getRepository(Tournoi::class)->findOneBy(['club' => $this->clubId]);
        $matchs = $em->getRepository(Rencontre::class)->findBy(['tournoi' => $tournoi], ['date' => 'ASC']);
        self::assertSame([['AS TINQUEUX', 'Tournoi', 'U13', 2], ['STADE DE REIMS', 'Tournoi', 'U13', null]], array_map(
            fn (Rencontre $r) => [$r->getExterieur(), $r->getCompetition(), $r->getCategorie()->getNom(), $r->getButsDomicile()], $matchs));
        self::assertSame($jour->modify('+1 day')->format('Y-m-d'), $matchs[1]->getDate()->format('Y-m-d'));

        // Convocation : celle du premier match ; la page du match renvoie au tournoi.
        $client->request('GET', '/tournois/' . $tournoi->getId() . '/convocation');
        self::assertResponseRedirects('/matchs/' . $matchs[0]->getId() . '/convocation');
        $crawler = $client->request('GET', '/matchs/' . $matchs[0]->getId());
        self::assertStringContainsString('Tournoi de Pâques', $crawler->filter('.titre-page')->text());

        // Calendrier du mois : le tournoi sur ses deux jours.
        $crawler = $client->request('GET', '/calendrier?mois=' . $jour->format('Y-m'));
        self::assertCount(2, $crawler->filter('a.cal-tournoi'));
    }
}
