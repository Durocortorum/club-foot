<?php

namespace App\Tests\Controller;

use App\Entity\Categorie;
use App\Entity\Club;
use App\Entity\Joueur;
use App\Entity\Stage;
use App\Entity\Utilisateur;
use App\Service\CreationClub;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Stages : création (dates, équipes, tarif, places), inscription par l'encadrement et paiement,
 * inscription par la famille depuis son espace (places, date limite), calendrier, droits.
 */
class StageTest extends WebTestCase
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
            foreach (['stage', 'utilisateur', 'joueur', 'categorie'] as $table) {
                $connexion->executeStatement("DELETE FROM $table WHERE club_id = ?", [$this->clubId]);
            }
            $connexion->executeStatement('DELETE FROM club WHERE id = ?', [$this->clubId]);
        }
        parent::tearDown();
    }

    public function testStages(): void
    {
        $id = bin2hex(random_bytes(4));
        $admin = (new Utilisateur())->setEmail("admin-$id@exemple.test")->setNom('Test')->setPrenom('Admin');
        $club = (new Club())->setNom("Club Test $id");
        static::getContainer()->get(CreationClub::class)->creer($club, $admin, 'motdepasse-admin');
        $this->clubId = $club->getId();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $u13 = $em->getRepository(Categorie::class)->findOneBy(['club' => $club, 'nom' => 'U13']);
        $seniors = $em->getRepository(Categorie::class)->findOneBy(['club' => $club, 'nom' => 'SENIORS']);
        $tom = (new Joueur())->setClub($club)->setCategorie($u13)->setNom('Petit')->setPrenom('Tom');
        $lea = (new Joueur())->setClub($club)->setCategorie($u13)->setNom('Martin')->setPrenom('Léa');
        $max = (new Joueur())->setClub($club)->setCategorie($seniors)->setNom('Durand')->setPrenom('Max');
        $parent = (new Utilisateur())->setClub($club)->setFamille(true)->setEmail("parent-$id@exemple.test")->setNom('Martin')->setPrenom('Famille')->setPassword('x')->addJoueur($lea);
        $coachSeniors = (new Utilisateur())->setClub($club)->setEmail("seniors-$id@exemple.test")->setNom('Bernard')->setPrenom('Coach')->setRolesAttribues(['ROLE_ELITE'])->setPassword('x');
        foreach ([$tom, $lea, $max, $parent, $coachSeniors] as $e) {
            $em->persist($e);
        }
        $em->flush();
        $debut = new \DateTimeImmutable('+10 days');

        // L'administrateur crée un stage U13 de 3 jours, 45 €, 2 places, inscriptions ouvertes aux familles.
        $this->client->loginUser($admin);
        $crawler = $this->client->request('GET', '/calendrier');
        $crawler = $this->client->click($crawler->selectLink('Stages')->link());
        $crawler = $this->client->click($crawler->selectLink('+ Nouveau stage')->link());
        $form = $crawler->selectButton('Enregistrer')->form();
        foreach ($form['stage[categories]'] as $case) {
            if ((string) $u13->getId() === $case->availableOptionValues()[0]) {
                $case->tick();
            }
        }
        $this->client->submit($form, [
            'stage[titre]' => 'Stage de la Toussaint',
            'stage[raison]' => 'Perfectionnement',
            'stage[debut]' => $debut->format('Y-m-d'),
            'stage[fin]' => $debut->modify('+2 days')->format('Y-m-d'),
            'stage[horaires]' => '9 h – 17 h',
            'stage[lieu]' => 'Stade municipal',
            'stage[tarif]' => '45',
            'stage[detailTarif]' => 'repas compris',
            'stage[places]' => '2',
            'stage[inscriptionsFamilles]' => '1',
        ]);
        $crawler = $this->client->followRedirect();
        $stage = $em->getRepository(Stage::class)->findOneBy(['club' => $this->clubId]);
        self::assertSame(3, $stage->getDuree());
        self::assertStringContainsString('45 € (repas compris)', $crawler->filter('main')->text());

        // Seuls les U13 sont proposés ; Tom est inscrit puis marqué payé.
        $inscrire = $crawler->filter('form.inscrire-stage');
        self::assertStringContainsString('PETIT Tom', $inscrire->text());
        self::assertStringNotContainsString('DURAND', $inscrire->text());
        $form = $inscrire->selectButton('Inscrire les joueurs cochés')->form();
        foreach ($form['joueurs'] as $case) {
            if ((string) $tom->getId() === $case->availableOptionValues()[0]) {
                $case->tick();
            }
        }
        $this->client->submit($form);
        $crawler = $this->client->followRedirect();
        self::assertStringContainsString('1 joueur inscrit', $crawler->filter('.flash-succes')->text());
        $this->client->submit($crawler->filter('#participants')->selectButton('Marquer payé')->form());
        $crawler = $this->client->followRedirect();
        self::assertStringContainsString('encaissé : 45,00 €', $crawler->filter('#participants')->text());

        // Le stage est dans le calendrier du mois (sur ses 3 jours s'ils sont dans le même mois).
        $crawler = $this->client->request('GET', '/calendrier?mois=' . $debut->format('Y-m'));
        self::assertGreaterThanOrEqual(1, $crawler->filter('a.cal-stage')->count());

        // La famille de Léa voit le stage et l'inscrit : le stage est alors complet.
        $this->client->loginUser($em->find(Utilisateur::class, $parent->getId()));
        $crawler = $this->client->request('GET', '/espace');
        self::assertStringContainsString('Stage de la Toussaint', $crawler->filter('.stage-famille')->text());
        $this->client->submit($crawler->selectButton('Inscrire Léa')->form());
        $crawler = $this->client->followRedirect();
        self::assertStringContainsString('Léa est inscrit(e)', $crawler->filter('.stage-famille')->text());
        self::assertStringContainsString('à régler', $crawler->filter('.stage-famille')->text());
        $em->clear();
        $stage = $em->find(Stage::class, $stage->getId());
        self::assertCount(2, $stage->getInscriptions());
        self::assertTrue($stage->isComplet());
        self::assertTrue($stage->inscription($em->find(Joueur::class, $lea->getId()))->isParFamille());

        // Elle annule l'inscription.
        $this->client->submit($crawler->selectButton("Annuler l'inscription")->form());
        $em->clear();
        self::assertCount(1, $em->find(Stage::class, $stage->getId())->getInscriptions());

        // Inscriptions closes (date limite passée) : plus de bouton, et la demande directe est refusée.
        $em->find(Stage::class, $stage->getId())->setDateLimite(new \DateTimeImmutable('-1 day'));
        $em->flush();
        $crawler = $this->client->request('GET', '/espace');
        self::assertCount(0, $crawler->selectButton('Inscrire Léa'));
        self::assertStringContainsString('Inscriptions closes', $crawler->filter('.stage-famille')->text());

        // L'éducateur des seniors voit le stage U13 mais ne le gère pas.
        $this->client->loginUser($em->find(Utilisateur::class, $coachSeniors->getId()));
        $crawler = $this->client->request('GET', '/stages/' . $stage->getId());
        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->selectLink('Modifier'));
        self::assertCount(0, $crawler->filter('form.inscrire-stage'));
        $this->client->request('GET', '/stages/' . $stage->getId() . '/modifier');
        self::assertResponseStatusCodeSame(403);
    }
}
