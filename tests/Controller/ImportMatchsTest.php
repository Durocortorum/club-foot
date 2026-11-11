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
 * Import d'un calendrier copié depuis un site : lecture, aperçu, création des matchs, pas de doublon.
 */
class ImportMatchsTest extends WebTestCase
{
    // Copie d'un tableau Transfermarkt : cellules séparées par des tabulations, logos vides, classement entre parenthèses.
    private const CALENDRIER = "Journée\tDate\tHeure\t\tÉquipe à domicile\t\tÉquipe à l'extérieur\tSystème de jeu\tEntraîneur\tRésultat\n"
        . "1\tdim. 23 août 2026\t15:00\t\tAJ Auxerre U19 (8.)\t\tStade Reims U19 (13.)\t?\tThomas Berlette\t1:1\n"
        . "2\tdim. 30 août 2026\t15:00\t\tStade Reims U19 (9.)\t\tFC Sochaux U19 (12.)\t?\tThomas Berlette\t0:3\n"
        . "13\tdim. 29 nov. 2026\tInconnu\t\tStade Reims U19\t\tFC Metz U19 (5.)\t\t\t-:-\n";

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

    /** Copie de la page FFF : noms en double (logo puis nom), heure entre les équipes, score sur une ou plusieurs lignes. */
    public function testLireUnCalendrierFff(): void
    {
        $texte = "sam 15 aoû 2026 - 11h30\nMATCHES AMICAUX U19/U18 LFHF - U19 - U18 Journée 33\nVALENCIENNES FC\nVALENCIENNES FC\n1\n0\nSTADE DE REIMS\nSTADE DE REIMS\n"
            . "dim 23 août 2026 - 15h00\nCHAMPIONNAT NATIONAL U19 - U19 - U18 Journée 1\nAJ AUXERRE\nAJ AUXERRE\n1 - 1\nSTADE DE REIMS\nSTADE DE REIMS\n"
            . "dim 30 août 2026 - 15h00\nCHAMPIONNAT NATIONAL U19 - U19 - U18 Journée 2\nSTADE DE REIMS\nSTADE DE REIMS\n0\n-\n3\nFC SOCHAUX\nFC SOCHAUX\n"
            . "dim 09 mai 2027 - 15h00\nCHAMPIONNAT NATIONAL U19 - U19 - U18 Journée 26\nSTADE DE REIMS\nSTADE DE REIMS\n15:00\nAJ AUXERRE\nAJ AUXERRE\n"
            // Ligne de compétition sans le mot « championnat » (U17) : jamais prise pour une équipe.
            . "sam 29 mai 2027 - 15h00\nU16 RÉGIONAL 1 - U17 - U16 Journée 26\nSTADE DE REIMS\nSTADE DE REIMS\n15:00\nUS ROYE NOYON\nUS ROYE NOYON\n";
        $analyse = static::getContainer()->get(\App\Service\ImportMatchs::class)->analyser($texte);

        self::assertSame('STADE DE REIMS', $analyse['nous']);
        self::assertSame(
            [
                ['2026-08-15', '11h30', false, 'VALENCIENNES FC', 1, 0, 'Amical'],
                ['2026-08-23', '15h00', false, 'AJ AUXERRE', 1, 1, 'Championnat'],
                ['2026-08-30', '15h00', true, 'FC SOCHAUX', 0, 3, 'Championnat'],
                ['2027-05-09', '15h00', true, 'AJ AUXERRE', null, null, 'Championnat'],
                ['2027-05-29', '15h00', true, 'US ROYE NOYON', null, null, 'Championnat'],
            ],
            array_map(fn (array $m) => [$m['date']->format('Y-m-d'), $m['heure'], $m['aDomicile'], $m['adversaire'], $m['butsDomicile'], $m['butsExterieur'], $m['competition']], $analyse['matchs']),
        );
    }

    /** Page de la poule collée par erreur dans « Importer des matchs » : seuls les matchs du club sont proposés. */
    public function testPageDePouleNeGardeQueLesMatchsDuClub(): void
    {
        $id = bin2hex(random_bytes(4));
        $admin = (new Utilisateur())->setEmail("admin-$id@exemple.test")->setNom('Test')->setPrenom('Coach');
        $club = (new Club())->setNom('Stade de Reims');
        static::getContainer()->get(CreationClub::class)->creer($club, $admin, 'motdepasse-admin');
        $this->clubId = $club->getId();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $categorie = $em->getRepository(Categorie::class)->findOneBy(['club' => $club, 'nom' => 'U19']);
        $texte = "dim 09 mai 2027 - 15h00\nRC STRASBOURG ALSACE\nRC STRASBOURG ALSACE\n15:00\nAS NANCY LORRAINE\nAS NANCY LORRAINE\n"
            . "dim 09 mai 2027 - 15h00\nDIJON FCO\nDIJON FCO\n15:00\nPARIS FC\nPARIS FC\n"
            . "dim 09 mai 2027 - 15h00\nAJ AUXERRE\nAJ AUXERRE\n15:00\nSTADE DE REIMS\nSTADE DE REIMS\n";

        $this->client->loginUser($admin);
        $crawler = $this->client->request('GET', '/matchs/importer?categorie=' . $categorie->getId());
        $crawler = $this->client->submit($crawler->selectButton('Lire le calendrier')->form(['texte' => $texte]));
        self::assertSelectorTextContains('.flash-info', '2 matchs entre d\'autres équipes sont ignorés');
        self::assertCount(1, $crawler->filter('input[name="lignes[]"]'));
        $this->client->submit($crawler->selectButton('Importer les matchs cochés')->form());
        $em->clear();
        $matchs = $em->getRepository(Rencontre::class)->findBy(['categorie' => $categorie->getId()]);
        self::assertSame([['AJ AUXERRE', 'STADE DE REIMS']], array_map(fn (Rencontre $r) => [$r->getDomicile(), $r->getExterieur()], $matchs));
    }

    public function testImporterUnCalendrier(): void
    {
        $id = bin2hex(random_bytes(4));
        $admin = (new Utilisateur())->setEmail("admin-$id@exemple.test")->setNom('Test')->setPrenom('Coach');
        $club = (new Club())->setNom("Stade de Reims $id");
        static::getContainer()->get(CreationClub::class)->creer($club, $admin, 'motdepasse-admin');
        $this->clubId = $club->getId();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $categorie = $em->getRepository(Categorie::class)->findOneBy(['club' => $club, 'nom' => 'U19']);

        $this->client->loginUser($admin);
        $crawler = $this->client->request('GET', '/matchs');
        $crawler = $this->client->click($crawler->selectLink('Importer des matchs')->link());
        self::assertResponseIsSuccessful();
        // L'aperçu répond 200 à un envoi de formulaire : avec Turbo, le navigateur ne l'afficherait pas.
        self::assertSelectorExists('form[data-turbo="false"]');

        // Lecture : trois matchs reconnus, notre équipe repérée, rien d'enregistré encore.
        $crawler = $this->client->submit($crawler->selectButton('Lire le calendrier')->form([
            'categorie' => $categorie->getId(), 'texte' => self::CALENDRIER,
        ]));
        self::assertSelectorTextContains('h2', '3 matchs reconnus');
        self::assertSelectorTextContains('main', 'STADE REIMS U19');
        self::assertCount(3, $crawler->filter('input[name="lignes[]"]'));
        self::assertCount(0, $em->getRepository(Rencontre::class)->findBy(['categorie' => $categorie]));

        // Import de deux matchs sur trois (le troisième décoché).
        $form = $crawler->selectButton('Importer les matchs cochés')->form();
        $form['lignes'][2]->untick();
        $this->client->submit($form);
        self::assertResponseRedirects();
        $this->client->followRedirect();
        self::assertSelectorTextContains('.flash-succes', '2 matchs importés pour U19');

        $em->clear();
        $matchs = $em->getRepository(Rencontre::class)->findBy(['categorie' => $categorie->getId()], ['date' => 'ASC']);
        self::assertSame(
            [['2026-08-23', '15h00', 'AJ AUXERRE', 'STADE DE REIMS ' . strtoupper($id), 1, 1, 'Championnat'], ['2026-08-30', '15h00', 'STADE DE REIMS ' . strtoupper($id), 'FC SOCHAUX', 0, 3, 'Championnat']],
            array_map(fn (Rencontre $r) => [$r->getDate()->format('Y-m-d'), $r->getHeure(), $r->getDomicile(), $r->getExterieur(), $r->getButsDomicile(), $r->getButsExterieur(), $r->getCompetition()], $matchs),
        );

        // Nouvelle lecture : les deux matchs importés sont signalés, seul le troisième est proposé, sans heure ni score.
        $crawler = $this->client->request('GET', '/matchs/importer?categorie=' . $categorie->getId());
        $crawler = $this->client->submit($crawler->selectButton('Lire le calendrier')->form(['texte' => self::CALENDRIER]));
        self::assertCount(2, $crawler->filter('a:contains("déjà enregistré")'));
        self::assertCount(1, $crawler->filter('input[name="lignes[]"]'));
        $this->client->submit($crawler->selectButton('Importer les matchs cochés')->form());
        $em->clear();
        $metz = $em->getRepository(Rencontre::class)->findOneBy(['categorie' => $categorie->getId(), 'date' => new \DateTimeImmutable('2026-11-29')]);
        self::assertSame([null, 'FC METZ', null], [$metz->getHeure(), $metz->getExterieur(), $metz->getButsDomicile()]);
        self::assertCount(3, $em->getRepository(Rencontre::class)->findBy(['categorie' => $categorie->getId()]));

        // « Annuler cet import » : seuls les matchs du dernier import (Metz) sont supprimés.
        $crawler = $this->client->followRedirect();
        self::assertSelectorTextContains('.annuler-import', 'Dernier import : 1 match pour U19');
        $this->client->submit($crawler->selectButton('Annuler cet import')->form());
        $this->client->followRedirect();
        self::assertSelectorTextContains('.flash-succes', 'Import annulé : 1 match supprimé.');
        self::assertSelectorNotExists('.annuler-import');
        $em->clear();
        self::assertSame(['2026-08-23', '2026-08-30'], array_map(
            fn (Rencontre $r) => $r->getDate()->format('Y-m-d'),
            $em->getRepository(Rencontre::class)->findBy(['categorie' => $categorie->getId()], ['date' => 'ASC']),
        ));

        // « Effacer les matchs de la saison » (liste filtrée sur l'équipe et la saison) : les deux restants.
        $crawler = $this->client->request('GET', '/matchs?categorie=' . $categorie->getId() . '&saison=2026/2027');
        $this->client->submit($crawler->selectButton('Effacer les matchs U19 de la saison 2026/2027')->form());
        $this->client->followRedirect();
        self::assertSelectorTextContains('.flash-succes', 'U19, saison 2026/2027 : 2 matchs supprimés.');
        $em->clear();
        self::assertCount(0, $em->getRepository(Rencontre::class)->findBy(['categorie' => $categorie->getId()]));
    }
}
