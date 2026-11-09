<?php

namespace App\Tests\Controller;

use App\Entity\Categorie;
use App\Entity\Club;
use App\Entity\CreneauEntrainement;
use App\Entity\Rencontre;
use App\Entity\Utilisateur;
use App\Service\CreationClub;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Calendrier du club : matchs et entraînements du mois, toutes équipes ou une seule.
 */
class CalendrierTest extends WebTestCase
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
            foreach (['entrainement', 'creneau_entrainement', 'rencontre', 'seance'] as $table) {
                $connexion->executeStatement("DELETE FROM $table WHERE club_id = ?", [$this->clubId]);
            }
            $connexion->executeStatement('DELETE FROM club WHERE id = ?', [$this->clubId]);
        }
        parent::tearDown();
    }

    public function testMatchsEtEntrainementsDuMois(): void
    {
        $id = bin2hex(random_bytes(4));
        $admin = (new Utilisateur())->setEmail("admin-$id@exemple.test")->setNom('Test')->setPrenom('Coach');
        $club = (new Club())->setNom("Club Test $id");
        static::getContainer()->get(CreationClub::class)->creer($club, $admin, 'motdepasse-admin');
        $this->clubId = $club->getId();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        [$u13, $autre] = $em->getRepository(Categorie::class)->findBy(['club' => $club], ['ordre' => 'ASC'], 2);

        // Le mois prochain : un match le 15 et un entraînement chaque mercredi (créneau).
        $mois = new \DateTimeImmutable('first day of next month');
        $em->persist((new CreneauEntrainement())->setClub($club)->setCategorie($u13)->setJour(3)->setHeureDebut('18:00')->setHeureFin('19:30'));
        $em->persist((new Rencontre())->setClub($club)->setCategorie($u13)->setDate($mois->modify('+14 days'))->setHeure('15h00')
            ->setDomicile("CLUB TEST $id")->setExterieur('FC METZ')->setCompetition('Championnat'));
        // Séances préparées : l'une un mercredi (jour d'entraînement), l'autre le jeudi suivant (seule).
        $mercredi = $mois->modify('wednesday this week') < $mois ? $mois->modify('next wednesday') : $mois->modify('wednesday this week');
        $em->persist((new \App\Entity\Seance())->setClub($club)->setCategorie($u13)->setDate($mercredi)->setTheme('Conservation'));
        $em->persist((new \App\Entity\Seance())->setClub($club)->setCategorie($u13)->setDate($mercredi->modify('+1 day'))->setTheme('Finition'));
        $em->flush();

        $this->client->loginUser($admin);
        $crawler = $this->client->request('GET', '/calendrier?categorie=&mois=' . $mois->format('Y-m'));
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.calendrier-entete h2', $mois->format('Y'));
        $match = $crawler->filter('.calendrier a.cal-match');
        self::assertCount(1, $match);
        self::assertStringContainsString('15h00', $match->text());
        self::assertStringContainsString('FC METZ (dom.)', $match->text());
        // Entraînements créés d'après le créneau, jusqu'à la fin du mois affiché : au moins 4 mercredis.
        self::assertGreaterThanOrEqual(4, $crawler->filter('.calendrier .cal-entrainement:not(.annule)')->count());
        self::assertStringContainsString('18h00', $crawler->filter('.calendrier .cal-entrainement')->first()->text());
        // La séance du mercredi est dans l'entraînement ; celle du jeudi apparaît seule.
        self::assertCount(1, $crawler->filter('.calendrier .cal-entrainement:contains("Entraînement · Conservation")'));
        $seule = $crawler->filter('.calendrier a.cal-seance');
        self::assertCount(1, $seule);
        self::assertStringContainsString('Finition', $seule->text());
        self::assertStringContainsString('/entrainements/', $seule->attr('href'));

        // Filtre sur une autre équipe : ni le match ni les entraînements de la première.
        $crawler = $this->client->request('GET', '/calendrier?categorie=' . $autre->getId() . '&mois=' . $mois->format('Y-m'));
        self::assertCount(0, $crawler->filter('.calendrier .cal-match, .calendrier .cal-entrainement'));
        self::assertSelectorTextContains('.vide', 'Aucun match ni entraînement ce mois-ci');

        // Mois suivant par la flèche : l'équipe choisie est gardée.
        $crawler = $this->client->click($crawler->selectLink('→')->link());
        self::assertSame((string) $autre->getId(), $crawler->filter('select[name="categorie"] option[selected]')->attr('value'));
    }
}
