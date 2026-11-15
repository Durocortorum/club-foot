<?php

namespace App\Tests\Controller;

use App\Entity\Categorie;
use App\Entity\Club;
use App\Entity\Joueur;
use App\Entity\ResultatTest;
use App\Entity\TestPhysique;
use App\Entity\TypeTest;
use App\Entity\Utilisateur;
use App\Service\CatalogueTests;
use App\Service\CreationClub;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Tests et progression : catalogue installé avec la reprise des anciens tests, catalogue filtré par âge,
 * séance de tests d'une équipe, courbes d'un joueur (un temps qui baisse est un progrès), tableau de l'équipe.
 */
class ProgressionTest extends WebTestCase
{
    private ?int $clubId = null;

    protected function tearDown(): void
    {
        if ($this->clubId) {
            $connexion = static::getContainer()->get(EntityManagerInterface::class)->getConnection();
            foreach (['resultat_test', 'type_test', 'test_physique', 'utilisateur', 'joueur', 'categorie'] as $table) {
                $connexion->executeStatement("DELETE FROM $table WHERE club_id = ?", [$this->clubId]);
            }
            $connexion->executeStatement('DELETE FROM club WHERE id = ?', [$this->clubId]);
        }
        parent::tearDown();
    }

    public function testCatalogueSeanceEtProgression(): void
    {
        $client = static::createClient();
        $id = bin2hex(random_bytes(4));
        $admin = (new Utilisateur())->setEmail("admin-$id@exemple.test")->setNom('Test')->setPrenom('Admin');
        $club = (new Club())->setNom("Club Test $id");
        static::getContainer()->get(CreationClub::class)->creer($club, $admin, 'motdepasse-admin');
        $this->clubId = $club->getId();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $u15 = $em->getRepository(Categorie::class)->findOneBy(['club' => $club, 'nom' => 'U15']);
        $u9 = $em->getRepository(Categorie::class)->findOneBy(['club' => $club, 'nom' => 'U9']);
        $tom = (new Joueur())->setClub($club)->setCategorie($u15)->setNom('Petit')->setPrenom('Tom');
        $leo = (new Joueur())->setClub($club)->setCategorie($u15)->setNom('Durand')->setPrenom('Léo');
        $em->persist($tom);
        $em->persist($leo);
        // Anciens tests (repris d'Access) : Tom, VMA 12 puis 13, 20 m en 3,6 s ; Léo, VMA 15.
        foreach ([[$tom, '-8 months', 12, 3.6], [$tom, '-4 months', 13, null], [$leo, '-4 months', 15, 3.3]] as [$j, $quand, $vma, $sprint]) {
            $em->persist((new TestPhysique())->setClub($club)->setJoueur($j)->setDate(new \DateTimeImmutable($quand))->setVma($vma)->setSprint20($sprint));
        }
        $em->flush();
        $client->loginUser($admin);

        // Catalogue : installé à la première visite, avec les anciens tests repris en résultats.
        $crawler = $client->request('GET', '/tests');
        self::assertResponseIsSuccessful();
        self::assertCount(\count(CatalogueTests::CATALOGUE), $crawler->filter('.fiche-test'));
        self::assertCount(5, $em->getRepository(ResultatTest::class)->findBy(['club' => $this->clubId]), '3 VMA et 2 sprints repris');
        // Filtré pour les U9 : pas de VAMEVAL (dès U13), mais la jonglerie et le demi-Cooper.
        $crawler = $client->request('GET', '/tests?categorie=' . $u9->getId());
        $noms = $crawler->filter('.fiche-test h3')->each(fn ($h) => $h->text());
        self::assertNotContains('VAMEVAL', $noms);
        self::assertContains('Jonglerie pied fort', $noms);
        self::assertContains('Demi-Cooper (6 min)', $noms);

        // Séance de tests U9 : la liste est celle de l'équipe (pas de VAMEVAL), les autres tests repliés.
        $crawler = $client->request('GET', '/tests/seance?categorie=' . $u9->getId());
        $equipe = $crawler->filter('form.choix-seance > .cases-tests label')->each(fn ($l) => $l->text());
        self::assertContains('Jonglerie pied fort (nb)', $equipe);
        self::assertNotContains('VAMEVAL (km/h)', $equipe);
        self::assertContains('VAMEVAL (km/h)', $crawler->filter('.autres-tests label')->each(fn ($l) => $l->text()));

        // Tout un secteur (préformation : U13 et U15) : choix proposé, joueurs de ses équipes avec leur équipe.
        self::assertCount(1, $crawler->filter('#categorie option[value="s:preformation"]'));
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $u13 = $em->getRepository(Categorie::class)->findOneBy(['club' => $this->clubId, 'nom' => 'U13']);
        $em->persist((new Joueur())->setClub($u13->getClub())->setCategorie($u13)->setNom('Blanc')->setPrenom('Noé'));
        $em->flush();
        $sprint20 = $em->getRepository(TypeTest::class)->findOneBy(['club' => $this->clubId, 'code' => 'sprint20']);
        $crawler = $client->request('GET', '/tests/seance?categorie=s:preformation&tests[]=' . $sprint20->getId());
        self::assertSelectorTextContains('h1 .compteur', 'Préformation');
        $lignes = $crawler->filter('table.saisie-tests tbody tr')->each(fn ($tr) => $tr->text());
        self::assertCount(3, $lignes);
        self::assertStringContainsString('BLANC NoéU13', $lignes[0]);
        self::assertSame('s:preformation', $crawler->filter('form.carte input[name="categorie"]')->attr('value'));

        // Séance de tests U15 : VMA « autre test » (repris) et 20 m pour tout le monde.
        $vma = $em->getRepository(TypeTest::class)->findOneBy(['club' => $this->clubId, 'code' => 'vma']);
        $sprint = $em->getRepository(TypeTest::class)->findOneBy(['club' => $this->clubId, 'code' => 'sprint20']);
        $crawler = $client->request('GET', '/tests/seance?categorie=' . $u15->getId() . '&tests[]=' . $vma->getId() . '&tests[]=' . $sprint->getId());
        $form = $crawler->selectButton('Enregistrer les résultats')->form();
        $form["valeurs[{$tom->getId()}][{$vma->getId()}]"] = '14,5';
        $form["valeurs[{$tom->getId()}][{$sprint->getId()}]"] = '3,4';
        $form["valeurs[{$leo->getId()}][{$vma->getId()}]"] = '14.5';
        $client->submit($form);
        $crawler = $client->followRedirect();
        self::assertStringContainsString('3 résultats enregistrés', $crawler->filter('.flash-succes')->text());

        // Équipe : Léo recule (15 → 14,5) mais garde la meilleure VMA, à égalité avec Tom.
        $leoLigne = $crawler->filter('table.tableau-progression tbody tr')->reduce(fn ($tr) => false !== stripos($tr->text(), 'Durand'));
        self::assertStringContainsString('★ 14,5', $leoLigne->text());
        self::assertStringContainsString('moins-bien', $leoLigne->filter('.progression-evolution')->first()->attr('class'));

        // Tom : fiche → « Voir la progression » ; VMA +2,5 km/h en 3 tests, 20 m −0,2 s = progrès.
        $crawler = $client->request('GET', '/joueurs/' . $tom->getId());
        self::assertStringContainsString('en 3 tests', $crawler->filter('#physique')->text());
        $crawler = $client->click($crawler->selectLink('📈 Voir la progression')->link());
        $cartes = $crawler->filter('.progression-serie');
        $carteVma = $cartes->reduce(fn ($c) => str_contains($c->filter('h3')->text(), 'VMA'));
        self::assertStringContainsString('+2,5 km/h', $carteVma->filter('.progression-evolution')->text());
        self::assertCount(3, $carteVma->filter('circle.marque'));
        $carteSprint = $cartes->reduce(fn ($c) => str_contains($c->filter('h3')->text(), 'Sprint 20 m'));
        self::assertStringContainsString('−0,2 s', $carteSprint->filter('.progression-evolution')->text());
        self::assertStringContainsString('mieux', $carteSprint->filter('.progression-evolution')->attr('class'), 'un temps qui baisse est un progrès');

        // Résultat isolé depuis la fiche : jonglerie.
        $jongle = $em->getRepository(TypeTest::class)->findOneBy(['club' => $this->clubId, 'code' => 'jonglePied']);
        $crawler = $client->request('GET', '/joueurs/' . $tom->getId() . '/tests/nouveau');
        $client->submit($crawler->selectButton('Enregistrer')->form(['test' => $jongle->getId(), 'valeur' => '42']));
        $crawler = $client->followRedirect();
        self::assertStringContainsString('Jonglerie pied fort : 42 nb enregistré pour Tom', $crawler->filter('.flash-succes')->text());
    }
}
