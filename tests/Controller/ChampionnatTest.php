<?php

namespace App\Tests\Controller;

use App\Entity\Categorie;
use App\Entity\Classement;
use App\Entity\Club;
use App\Entity\Rencontre;
use App\Entity\Utilisateur;
use App\Service\CreationClub;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Championnat : classement collé depuis la FFF, ligne du club, forme, prochain adversaire et son rang.
 */
class ChampionnatTest extends WebTestCase
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
            foreach (['classement', 'rencontre'] as $table) {
                $connexion->executeStatement("DELETE FROM $table WHERE club_id = ?", [$this->clubId]);
            }
            $connexion->executeStatement('DELETE FROM club WHERE id = ?', [$this->clubId]);
        }
        parent::tearDown();
    }

    public function testClassementFormeEtProchainMatch(): void
    {
        $id = bin2hex(random_bytes(4));
        $admin = (new Utilisateur())->setEmail("admin-$id@exemple.test")->setNom('Test')->setPrenom('Coach');
        $club = (new Club())->setNom("Stade Test $id");
        static::getContainer()->get(CreationClub::class)->creer($club, $admin, 'motdepasse-admin');
        $this->clubId = $club->getId();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        // Première équipe qui joue des matchs (l'école de foot joue des plateaux).
        $equipe = $em->getRepository(Categorie::class)->findOneBy(['club' => $club, 'plateaux' => false], ['ordre' => 'ASC']);
        $nous = mb_strtoupper("Stade Test $id");
        $saison = Rencontre::saisonDe(new \DateTimeImmutable('today'));
        [$debutSaison] = Rencontre::bornesSaison($saison);

        // Deux matchs de championnat joués (1 V, 1 D), un amical (hors championnat), un à venir contre Metz.
        foreach ([
            [$debutSaison->modify('+60 days'), $nous, 'AUXERRE', 2, 0, 'Championnat'],
            [$debutSaison->modify('+67 days'), 'ESTAC TROYES', $nous, 3, 1, 'Championnat'],
            [$debutSaison->modify('+70 days'), $nous, 'VALENCIENNES', 1, 1, 'Amical'],
            [new \DateTimeImmutable('today +6 days'), $nous, 'FC METZ', null, null, 'Championnat'],
        ] as [$date, $domicile, $exterieur, $bd, $be, $competition]) {
            $em->persist((new Rencontre())->setClub($club)->setCategorie($equipe)->setDate($date)->setCompetition($competition)
                ->setDomicile($domicile)->setExterieur($exterieur)->setButsDomicile($bd)->setButsExterieur($be)->setHeure(null === $bd ? '15h00' : null));
        }
        $em->flush();

        $this->client->loginUser($admin);
        $crawler = $this->client->request('GET', '/matchs?categorie=' . $equipe->getId());
        $crawler = $this->client->click($crawler->selectLink('Championnat')->link());
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.vide', 'Pas encore de classement');
        self::assertCount(3, $crawler->filter('section:contains("Matchs de championnat") tbody tr'), 'l\'amical n\'est pas un match de championnat');

        // Copie de la page FFF : en-têtes, rang, progression, nom en double, 10 colonnes.
        $copie = "CLASSEMENT\nClassement sous réserve d'éventuelles procédures en cours ou à venir\nPr.\nEquipe\nPts\nJ.\nG.\nN.\nP.\nF.\nP/Bo.\nBp.\nBc.\nDiff.\nSérie en cours\n"
            . "1\nESTAC TROYES\nESTAC TROYES\n24\n10\n7\n3\n0\n0\n0\n25\n11\n14\n"
            . "2\n1\n$nous\n$nous\n20\n10\n6\n2\n2\n0\n0\n19\n11\n8\n"
            . "3\n1\nFC METZ\nFC METZ\n16\n10\n4\n4\n2\n0\n0\n18\n12\n6\n"
            . "Mentions légales\n";
        // Une seule case « Importer depuis la FFF » : le classement est reconnu et enregistré, sans le relire comme des matchs.
        $this->client->submit($crawler->selectButton('Importer')->form(['texte' => $copie]));
        $crawler = $this->client->followRedirect();
        self::assertSelectorTextContains('.flash-succes', 'Classement enregistré (3 équipes)');
        self::assertCount(4, $em->getRepository(Rencontre::class)->findBy(['categorie' => $equipe->getId()]));
        self::assertSame(['1', '2', '3'], $crawler->filter('.classement tbody td.rang-classement')->each(fn ($n) => $n->text()));
        self::assertStringContainsString($nous, $crawler->filter('.classement tr.nous')->text());

        // Tuiles : notre rang, la forme du championnat (D puis V, l'amical exclu), le prochain adversaire et son rang.
        self::assertStringContainsString('2e', $crawler->filter('.tuiles .tuile')->first()->text());
        self::assertSame(['V', 'D'], $crawler->filter('.tuile-forme .score')->each(fn ($n) => $n->text()));
        self::assertStringContainsString('FC METZ', $crawler->filter('a.tuile')->text());
        self::assertStringContainsString('3e, 16 pts', $crawler->filter('a.tuile')->text());

        // Rappel : aucun juste après la mise à jour ; si elle date d'avant les deux matchs joués, il apparaît.
        self::assertSelectorNotExists('.rappel-classement');
        $em->getConnection()->executeStatement('UPDATE classement SET mis_ajour_le = ? WHERE club_id = ?', [$debutSaison->format('Y-m-d 12:00:00'), $this->clubId]);
        $this->client->request('GET', '/championnat?categorie=' . $equipe->getId() . '&saison=' . $saison);
        self::assertSelectorTextContains('.rappel-classement', 'Classement à mettre à jour : 2 matchs de championnat ont été joués depuis la mise à jour du ' . $debutSaison->format('d/m'));

        // Fiche du match perdu à Troyes : classement recalculé avec ce match (Troyes 27, nous 23), nous 2e comme avant,
        // notre ligne et celle de l'adversaire repérées. Pas de classement sur la fiche de l'amical.
        $troyes = $em->getRepository(Rencontre::class)->findOneBy(['categorie' => $equipe->getId(), 'domicile' => 'ESTAC TROYES']);
        $crawler = $this->client->request('GET', '/matchs/' . $troyes->getId());
        self::assertSelectorTextContains('.classement-match .classement-rang', '2e');
        self::assertSelectorTextContains('.classement-match .classement-evolution', 'même place');
        self::assertStringContainsString($nous, $crawler->filter('.classement-compact tr.nous')->text());
        self::assertStringContainsString('ESTAC TROYES', $crawler->filter('.classement-compact tr.adversaire')->text());
        self::assertStringContainsString('27', $crawler->filter('.classement-compact tbody tr')->first()->text());
        // Liste des matchs filtrée sur l'équipe : le classement de sa poule aussi.
        $crawler = $this->client->request('GET', '/matchs?categorie=' . $equipe->getId() . '&saison=' . $saison);
        self::assertSelectorTextContains('.classement-match .classement-rang', '2e');
        self::assertCount(0, $crawler->filter('.classement-compact tr.adversaire'), 'pas d\'adversaire sans match');
        // Équipe gardée d'une page à l'autre : les pages ouvertes sans équipe reprennent celle choisie, jusqu'à « Toutes ».
        foreach (['/statistiques', '/presences', '/entrainements', '/joueurs', '/matchs'] as $page) {
            $this->client->request('GET', $page);
            self::assertSelectorTextContains('h1 .equipe-titre', $equipe->getNom(), "$page reprend l'équipe choisie");
        }
        $this->client->request('GET', '/matchs?categorie=');
        self::assertSelectorNotExists('h1 .equipe-titre');
        $this->client->request('GET', '/statistiques');
        self::assertSelectorNotExists('h1 .equipe-titre', '« Toutes les catégories » efface le choix');
        $amical = $em->getRepository(Rencontre::class)->findOneBy(['categorie' => $equipe->getId(), 'competition' => 'Amical']);
        $this->client->request('GET', '/matchs/' . $amical->getId());
        self::assertSelectorNotExists('.classement-match');
    }
}
