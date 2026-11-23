<?php

namespace App\Tests\Controller;

use App\Entity\Categorie;
use App\Entity\Club;
use App\Entity\CombinaisonCpa;
use App\Entity\Exercice;
use App\Entity\Rencontre;
use App\Entity\Seance;
use App\Entity\TableauTactique;
use App\Entity\Utilisateur;
use App\Service\CreationClub;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Bibliothèque de coups de pied arrêtés : création d'un corner (schéma et rôles de départ), copie dans le tableau
 * tactique d'un match et dans une séance d'entraînement. Données fictives, club supprimé à la fin.
 */
class CpaTest extends WebTestCase
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
            foreach (['combinaison_cpa', 'tableau_tactique', 'rencontre', 'seance', 'club'] as $table) {
                $connexion->executeStatement("DELETE FROM $table WHERE " . ('club' === $table ? 'id' : 'club_id') . ' = ?', [$this->clubId]);
            }
        }
        parent::tearDown();
    }

    public function testBibliothequeDeCoupsDePiedArretes(): void
    {
        $id = bin2hex(random_bytes(4));
        $admin = (new Utilisateur())->setEmail("admin-$id@exemple.test")->setNom('Test')->setPrenom('Coach');
        $club = (new Club())->setNom("Club Test $id");
        static::getContainer()->get(CreationClub::class)->creer($club, $admin, 'motdepasse-admin');
        $this->clubId = $club->getId();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $u15 = $em->getRepository(Categorie::class)->findOneBy(['club' => $club, 'nom' => 'U15']);
        $match = (new Rencontre())->setClub($club)->setCategorie($u15)->setDate(new \DateTimeImmutable('+4 days'))->setDomicile("CLUB TEST $id")->setExterieur('US Vallée Verte');
        $seance = (new Seance())->setClub($club)->setCategorie($u15)->setDate(new \DateTimeImmutable('+2 days'))->setTheme('Coups de pied arrêtés');
        $em->persist($match);
        $em->persist($seance);
        $em->flush();

        $this->client->loginUser($admin);
        $crawler = $this->client->request('GET', '/entrainements');
        $crawler = $this->client->click($crawler->selectLink('🚩 Coups de pied arrêtés')->link());
        self::assertResponseIsSuccessful();
        $crawler = $this->client->click($crawler->selectLink('+ Nouvelle combinaison')->link());
        // Corner proposé avec un schéma (ballon, tireur…) et des rôles de départ.
        self::assertSame('10', $crawler->filter('input[name="roles[0][n]"]')->attr('value'));
        self::assertSame('Tireur', $crawler->filter('input[name="roles[0][role]"]')->attr('value'));
        $form = $crawler->selectButton('Enregistrer dans la bibliothèque')->form(['titre' => 'Corner au 2e poteau', 'consignes' => 'Ballon rentrant.']);
        $this->client->submit($form);
        $this->client->followRedirect();
        self::assertSelectorTextContains('.cpa-carte h2', 'Corner au 2e poteau');
        self::assertSelectorTextContains('.cpa-roles', 'Finition 1er poteau');

        $combinaison = static::getContainer()->get(EntityManagerInterface::class)->getRepository(CombinaisonCpa::class)->findOneBy(['club' => $this->clubId]);
        self::assertNotEmpty($combinaison->getSchema()['objets']);

        // Depuis le tableau tactique du match : ajout direct.
        $crawler = $this->client->request('GET', '/cpa?match=' . $match->getId());
        $this->client->submit($crawler->selectButton('+ Ajouter au match')->form());
        self::assertResponseRedirects('/matchs/' . $match->getId() . '/tactique');
        $this->client->followRedirect();
        self::assertSelectorTextContains('.tableau-tactique h2', 'Corner au 2e poteau');
        $tableau = static::getContainer()->get(EntityManagerInterface::class)->getRepository(TableauTactique::class)->findOneBy(['rencontre' => $match->getId()]);
        self::assertStringContainsString('10 : Tireur', $tableau->getConsignes());

        // Dans une séance d'entraînement.
        $crawler = $this->client->request('GET', '/cpa?seance=' . $seance->getId());
        $this->client->submit($crawler->selectButton('+ Ajouter à la séance')->form());
        self::assertResponseRedirects();
        $exercice = static::getContainer()->get(EntityManagerInterface::class)->getRepository(Exercice::class)->findOneBy(['seance' => $seance->getId()]);
        self::assertSame('Corner : Corner au 2e poteau', $exercice->getTitre());
    }
}
