<?php

namespace App\Tests\Controller;

use App\Entity\Categorie;
use App\Entity\Club;
use App\Entity\Evenement;
use App\Entity\Joueur;
use App\Entity\Utilisateur;
use App\Service\CreationClub;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Événements du club : création (tout le club par l'administrateur, ses équipes par l'éducateur),
 * affichage dans la liste, le calendrier et l'espace des familles.
 */
class EvenementTest extends WebTestCase
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
            $connexion->executeStatement('DELETE FROM evenement WHERE club_id = ?', [$this->clubId]);
            $connexion->executeStatement('DELETE FROM joueur WHERE club_id = ?', [$this->clubId]);
            $connexion->executeStatement('DELETE FROM club WHERE id = ?', [$this->clubId]);
        }
        parent::tearDown();
    }

    public function testEvenementsDuClub(): void
    {
        $id = bin2hex(random_bytes(4));
        $admin = (new Utilisateur())->setEmail("admin-$id@exemple.test")->setNom('Test')->setPrenom('Admin');
        $club = (new Club())->setNom("Club Test $id");
        static::getContainer()->get(CreationClub::class)->creer($club, $admin, 'motdepasse-admin');
        $this->clubId = $club->getId();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $categories = $em->getRepository(Categorie::class)->findBy(['club' => $club], ['ordre' => 'ASC']);
        $ecole = $categories[0]; // école de foot
        $formation = array_values(array_filter($categories, fn (Categorie $c) => Categorie::SECTEUR_FORMATION === $c->getSecteur()))[0];
        $lea = (new Joueur())->setClub($club)->setCategorie($ecole)->setNom('Martin')->setPrenom('Léa');
        $parent = (new Utilisateur())->setClub($club)->setFamille(true)->setEmail("parent-$id@exemple.test")->setNom('Martin')->setPrenom('Julie')->setPassword('x')->addJoueur($lea);
        $coach = (new Utilisateur())->setClub($club)->setEmail("coach-$id@exemple.test")->setNom('Durand')->setPrenom('Coach')->setRolesAttribues(['ROLE_FORMATION'])->setPassword('x');
        array_map([$em, 'persist'], [$lea, $parent, $coach]);
        $em->flush();
        $jour = new \DateTimeImmutable('today +10 days');

        // L'administrateur crée un événement pour tout le club (aucune équipe cochée).
        $this->client->loginUser($admin);
        $crawler = $this->client->request('GET', '/evenements');
        $crawler = $this->client->click($crawler->selectLink('+ Nouvel événement')->link());
        $this->client->submit($crawler->selectButton('Enregistrer')->form([
            'evenement[titre]' => 'Repas de fin d\'année', 'evenement[type]' => 'Repas / soirée',
            'evenement[debut]' => $jour->format('Y-m-d') . 'T19:30', 'evenement[lieu]' => 'Club-house',
        ]));
        $crawler = $this->client->followRedirect();
        self::assertSelectorTextContains('.flash-succes', 'Événement enregistré');
        self::assertStringContainsString('Pour : tout le club', $crawler->filter('.evenement')->text());
        self::assertStringContainsString('Club-house', $crawler->filter('.evenement')->text());

        // Il apparaît dans le calendrier du mois, même filtré sur une équipe (il concerne tout le club).
        $crawler = $this->client->request('GET', '/calendrier?categorie=' . $formation->getId() . '&mois=' . $jour->format('Y-m'));
        self::assertStringContainsString('Repas de fin d\'année', $crawler->filter('.calendrier .cal-evenement')->text());
        self::assertStringContainsString('19h30', $crawler->filter('.calendrier .cal-evenement')->text());

        // L'éducateur Formation : pas d'événement pour tout le club ; un pour son équipe, interne à l'encadrement.
        $this->client->loginUser($coach);
        $crawler = $this->client->request('GET', '/evenements/nouveau');
        self::assertCount(0, $crawler->filter('input[name="evenement[categories][]"][value="' . $ecole->getId() . '"]'), 'équipes des autres secteurs non proposées');
        $form = $crawler->selectButton('Enregistrer')->form(['evenement[titre]' => 'Réunion des éducateurs', 'evenement[type]' => 'Réunion', 'evenement[debut]' => $jour->format('Y-m-d') . 'T18:00']);
        $form['evenement[visibleFamilles]']->untick();
        $crawler = $this->client->submit($form);
        self::assertSelectorTextContains('form', 'Cochez au moins une équipe : un événement pour tout le club est créé par l\'administrateur.');
        $form = $crawler->selectButton('Enregistrer')->form();
        $form['evenement[categories]'][0]->tick();
        $form['evenement[visibleFamilles]']->untick();
        $this->client->submit($form);
        self::assertResponseRedirects('/evenements');
        // Il ne peut pas modifier l'événement du club.
        $repas = $em->getRepository(Evenement::class)->findOneBy(['titre' => 'Repas de fin d\'année']);
        $this->client->request('GET', '/evenements/' . $repas->getId() . '/modifier');
        self::assertResponseStatusCodeSame(403);

        // La famille voit le repas (tout le club), pas la réunion interne de l'encadrement.
        $this->client->loginUser($parent);
        $this->client->request('GET', '/espace');
        self::assertSelectorTextContains('#evenements', 'Repas de fin d\'année');
        self::assertSelectorTextNotContains('#evenements', 'Réunion des éducateurs');
    }
}
