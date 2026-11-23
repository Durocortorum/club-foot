<?php

namespace App\Tests\Controller;

use App\Entity\Categorie;
use App\Entity\Club;
use App\Entity\Convocation;
use App\Entity\Joueur;
use App\Entity\Rencontre;
use App\Entity\TableauTactique;
use App\Entity\Utilisateur;
use App\Service\CreationClub;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Tableau tactique d'un match : création (formation de départ, zone de pressing, étape avec trajet courbé),
 * présentation, partage avec la famille d'un joueur convoqué seulement. Données fictives, club supprimé à la fin.
 */
class TactiqueTest extends WebTestCase
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
            foreach (['tableau_tactique', 'rencontre', 'joueur', 'utilisateur', 'club'] as $table) {
                $connexion->executeStatement("DELETE FROM $table WHERE " . ('club' === $table ? 'id' : 'club_id') . ' = ?', [$this->clubId]);
            }
        }
        parent::tearDown();
    }

    public function testTableauTactique(): void
    {
        $id = bin2hex(random_bytes(4));
        $admin = (new Utilisateur())->setEmail("admin-$id@exemple.test")->setNom('Test')->setPrenom('Coach');
        $club = (new Club())->setNom("Club Test $id");
        static::getContainer()->get(CreationClub::class)->creer($club, $admin, 'motdepasse-admin');
        $this->clubId = $club->getId();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $u15 = $em->getRepository(Categorie::class)->findOneBy(['club' => $club, 'nom' => 'U15']);
        $rencontre = (new Rencontre())->setClub($club)->setCategorie($u15)->setCompetition('Championnat')->setSysteme('4 3 3')
            ->setDate(new \DateTimeImmutable('+5 days'))->setDomicile("CLUB TEST $id")->setExterieur('US Vallée Verte');
        $em->persist($rencontre);
        $em->flush();

        $this->client->loginUser($admin);
        $crawler = $this->client->request('GET', '/matchs/' . $rencontre->getId());
        $crawler = $this->client->click($crawler->selectLink('📋 Tactique')->link());
        self::assertResponseIsSuccessful();
        $crawler = $this->client->click($crawler->selectLink('+ Nouveau tableau')->link());
        self::assertResponseIsSuccessful();
        // Premier tableau : notre organisation (4 3 3), 11 joueurs sur le terrain entier, formation proposée.
        self::assertSelectorExists('select[data-schema-target="formation"] option[value="4 3 3"][selected]');
        $depart = json_decode($crawler->filter('input[name="schema"]')->attr('value'), true);
        self::assertSame('entier', $depart['terrain']);
        self::assertCount(11, $depart['objets']);
        self::assertSame('Notre organisation', $crawler->filter('input[name="titre"]')->attr('value'));

        // Zone de pressing, flèche, et une étape où le 9 va presser par un trajet courbé.
        $schema = $depart;
        $schema['objets'][] = ['t' => 'zone', 'x' => 86, 'y' => 8, 'w' => 21, 'h' => 58, 'c' => 'rouge', 's' => 'pressing'];
        $schema['etapes'] = [array_fill(0, 12, null)];
        $schema['etapes'][0][10] = [99, 38, [[95, 30]]];
        $form = $crawler->selectButton('Enregistrer le tableau')->form([
            'titre' => 'Pressing', 'consignes' => 'On presse leur gardien.', 'schema' => json_encode($schema),
        ]);
        $form['partage']->tick();
        $this->client->submit($form);
        self::assertResponseRedirects('/matchs/' . $rencontre->getId() . '/tactique');
        $crawler = $this->client->followRedirect();
        self::assertSelectorTextContains('.tableau-tactique h2', 'Pressing');
        self::assertSelectorTextContains('.tableau-tactique', 'montré aux joueurs');

        $tableau = $em->getRepository(TableauTactique::class)->findOneBy(['rencontre' => $rencontre]);
        self::assertSame('pressing', $tableau->getSchema()['objets'][11]['s']);
        // Le trajet courbé de l'étape est gardé.
        self::assertEquals([99, 38, [[95, 30]]], $tableau->getSchema()['etapes'][0][10]);

        // Présentation pour la causerie.
        $this->client->request('GET', '/matchs/' . $rencontre->getId() . '/tactique/presenter');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.presentation-diapo h2', 'Pressing');

        // Famille : le plan de jeu n'est visible que si son enfant est convoqué (convocation envoyée).
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $club = $em->find(Club::class, $this->clubId);
        $u15 = $em->find(Categorie::class, $u15->getId());
        $leo = (new Joueur())->setClub($club)->setCategorie($u15)->setNom('Martin')->setPrenom('Léo');
        $tom = (new Joueur())->setClub($club)->setCategorie($u15)->setNom('Durand')->setPrenom('Tom');
        $parentLeo = (new Utilisateur())->setClub($club)->setFamille(true)->setEmail("parent-leo-$id@exemple.test")->setNom('Martin')->setPrenom('Famille')->addJoueur($leo);
        $parentTom = (new Utilisateur())->setClub($club)->setFamille(true)->setEmail("parent-tom-$id@exemple.test")->setNom('Durand')->setPrenom('Famille')->addJoueur($tom);
        $rencontre = $em->find(Rencontre::class, $rencontre->getId());
        $convocation = (new Convocation($rencontre))->setClub($club)->setJoueurs([$leo])->marquerEnvoyee();
        foreach ($convocation->getConvoques() as $cq) {
            $cq->setClub($club);
        }
        foreach ([$leo, $tom, $parentLeo, $parentTom, $convocation] as $objet) {
            $em->persist($objet);
        }
        $em->flush();

        $this->client->loginUser($parentLeo);
        $crawler = $this->client->request('GET', '/espace');
        $crawler = $this->client->click($crawler->selectLink('📋 Plan de jeu du coach')->link());
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.tableau-tactique', 'On presse leur gardien.');

        $this->client->loginUser($parentTom);
        $this->client->request('GET', '/espace/match/' . $rencontre->getId() . '/tactique');
        self::assertResponseStatusCodeSame(404);
    }
}
