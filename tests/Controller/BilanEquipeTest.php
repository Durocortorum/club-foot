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
 * Statistiques, onglet Équipe : forme, domicile / extérieur, compétitions, repères, mi-temps.
 */
class BilanEquipeTest extends WebTestCase
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
            $connexion->executeStatement('DELETE FROM club WHERE id = ?', [$this->clubId]);
        }
        parent::tearDown();
    }

    public function testBilanCompletDeLEquipe(): void
    {
        $id = bin2hex(random_bytes(4));
        $admin = (new Utilisateur())->setEmail("admin-$id@exemple.test")->setNom('Test')->setPrenom('Coach');
        $club = (new Club())->setNom("Stade Test $id");
        static::getContainer()->get(CreationClub::class)->creer($club, $admin, 'motdepasse-admin');
        $this->clubId = $club->getId();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $equipe = $em->getRepository(Categorie::class)->findOneBy(['club' => $club], ['ordre' => 'ASC']);
        $nous = mb_strtoupper("Stade Test $id");

        // [date, domicile ?, adversaire, pour, contre, compétition, mi-temps pour, mi-temps contre]
        $matchs = [
            ['2026-09-06', true, 'AUXERRE', 4, 0, 'Championnat', 1, 0],  // V, plus large victoire
            ['2026-09-13', false, 'METZ', 1, 3, 'Championnat', 1, 0],    // D (menés à la fin, en tête à la pause)
            ['2026-09-20', true, 'SEDAN', 2, 2, 'Coupe', 0, 1],          // N (menés à la pause)
            ['2026-09-27', false, 'TROYES', 2, 1, 'Championnat', null, null], // V
            ['2026-10-04', true, 'NANCY', 1, 0, 'Championnat', 0, 0],    // V : 2 victoires de suite
            ['2026-10-11', true, 'DIJON', null, null, 'Championnat', null, null], // pas encore joué : ignoré
        ];
        foreach ($matchs as [$date, $domicile, $adversaire, $pour, $contre, $competition, $mtPour, $mtContre]) {
            $r = (new Rencontre())->setClub($club)->setCategorie($equipe)->setDate(new \DateTimeImmutable($date))->setCompetition($competition)
                ->setDomicile($domicile ? $nous : $adversaire)->setExterieur($domicile ? $adversaire : $nous);
            if (null !== $pour) {
                $r->setButsDomicile($domicile ? $pour : $contre)->setButsExterieur($domicile ? $contre : $pour);
            }
            if (null !== $mtPour) {
                $r->setButsDomicileMiTemps($domicile ? $mtPour : $mtContre)->setButsExterieurMiTemps($domicile ? $mtContre : $mtPour);
            }
            $em->persist($r);
        }
        $em->flush();

        $this->client->loginUser($admin);
        $crawler = $this->client->request('GET', '/statistiques?vue=equipe&saison=2026/2027&categorie=' . $equipe->getId());
        self::assertResponseIsSuccessful();

        // Forme : les 5 matchs joués, série en cours.
        self::assertSame(['V', 'D', 'N', 'V', 'V'], $crawler->filter('.forme .score')->each(fn ($n) => $n->text()));
        self::assertSelectorTextContains('.bilan-equipe .pastille', '2 victoires de suite');

        // Total, domicile, extérieur : J V N D, buts, différence, points.
        $lignes = $crawler->filter('.bilan-lignes')->first()->filter('tbody tr')->each(fn ($tr) => $tr->filter('td')->each(fn ($td) => trim($td->text())));
        self::assertSame(['Total', '5', '3', '1', '1', '10 – 6', '+4', '10 (2,00/match)', '60 %'], $lignes[0]);
        self::assertSame(['Domicile', '3', '2', '1', '0', '7 – 2', '+5', '7 (2,33/match)', '67 %'], $lignes[1]);
        self::assertSame(['Extérieur', '2', '1', '0', '1', '3 – 4', '-1', '3 (1,50/match)', '50 %'], $lignes[2]);
        // Par compétition (Championnat et Coupe).
        self::assertCount(2, $crawler->filter('.bilan-lignes')->eq(1)->filter('tbody tr'));

        // Repères.
        self::assertSelectorTextContains('.reperes', 'Plus large victoire : 4 – 0 contre AUXERRE');
        self::assertSelectorTextContains('.reperes', 'Plus lourde défaite : 1 – 3 à METZ');
        self::assertSame('2', $crawler->filter('.tuile:contains("sans encaisser") .tuile-valeur')->text());
        self::assertSame('0', $crawler->filter('.tuile:contains("sans marquer") .tuile-valeur')->text());

        // Mi-temps : en tête à la pause (1 V, 1 D), à égalité (1 V), mené (1 N).
        $miTemps = $crawler->filter('table:contains("À la pause") tbody tr')->each(fn ($tr) => $tr->filter('td')->each(fn ($td) => $td->text()));
        self::assertSame([['En tête à la pause', '2', '1', '0', '1'], ['À égalité à la pause', '1', '1', '0', '0'], ['Mené à la pause', '1', '0', '1', '0']], $miTemps);
    }
}
