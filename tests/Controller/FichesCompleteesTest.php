<?php

namespace App\Tests\Controller;

use App\Entity\Categorie;
use App\Entity\Club;
use App\Entity\Conducteur;
use App\Entity\Educateur;
use App\Entity\Joueur;
use App\Entity\Utilisateur;
use App\Service\CreationClub;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Fiches complétées : équipe féminine (cochée d'office d'après le nom), ancien club et autorisations du joueur,
 * date du diplôme, permis (avec l'état du conducteur du club) et équipes de l'éducateur.
 */
class FichesCompleteesTest extends WebTestCase
{
    private ?int $clubId = null;

    protected function tearDown(): void
    {
        if ($this->clubId) {
            $connexion = static::getContainer()->get(EntityManagerInterface::class)->getConnection();
            $connexion->executeStatement('DELETE ec FROM educateur_categorie ec JOIN educateur e ON e.id = ec.educateur_id WHERE e.club_id = ?', [$this->clubId]);
            foreach (['conducteur', 'joueur', 'educateur', 'utilisateur', 'categorie'] as $table) {
                $connexion->executeStatement("DELETE FROM $table WHERE club_id = ?", [$this->clubId]);
            }
            $connexion->executeStatement('DELETE FROM club WHERE id = ?', [$this->clubId]);
        }
        parent::tearDown();
    }

    public function testFichesCompletees(): void
    {
        $client = static::createClient();
        $id = bin2hex(random_bytes(4));
        $admin = (new Utilisateur())->setEmail("admin-$id@exemple.test")->setNom('Test')->setPrenom('Admin');
        $club = (new Club())->setNom("Club Test $id");
        static::getContainer()->get(CreationClub::class)->creer($club, $admin, 'motdepasse-admin');
        $this->clubId = $club->getId();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $u15 = $em->getRepository(Categorie::class)->findOneBy(['club' => $club, 'nom' => 'U15']);
        $joueur = (new Joueur())->setClub($club)->setCategorie($u15)->setNom('Petit')->setPrenom('Tom')->setDateNaissance(new \DateTimeImmutable('2012-03-14'));
        $em->persist($joueur);
        $em->persist((new Conducteur())->setClub($club)->setNom('Karim Coach')->setControleLe(new \DateTimeImmutable('-2 months')));
        $em->flush();
        $client->loginUser($admin);

        // Catégorie « U13 Filles » : cochée féminine d'office, pastille F dans la liste.
        $crawler = $client->request('GET', '/club/categories/nouvelle');
        $client->submit($crawler->selectButton('Enregistrer')->form(['categorie[nom]' => 'U13 Filles', 'categorie[secteur]' => 'preformation']));
        $filles = $em->getRepository(Categorie::class)->findOneBy(['club' => $club, 'nom' => 'U13 FILLES']);
        self::assertTrue($filles->isFeminine());
        self::assertFalse($u15->isFeminine());
        $crawler = $client->request('GET', '/club/categories');
        self::assertCount(1, $crawler->filter('.pastille.feminine'));
        // Rangées à leur place d'âge : U13 Filles après U13, U18 entre U17 et U19 (et non en bas de la liste).
        $crawler = $client->request('GET', '/club/categories/nouvelle');
        $client->submit($crawler->selectButton('Enregistrer')->form(['categorie[nom]' => 'U18', 'categorie[secteur]' => 'formation']));
        $crawler = $client->request('GET', '/club/categories');
        $ordre = $crawler->filter('table tbody tr td strong')->each(fn ($n) => $n->text());
        self::assertSame(['U13', 'U13 FILLES', 'U15', 'U17', 'U18', 'U19', 'SENIORS'], \array_slice($ordre, 3, 7));
        self::assertTrue(Categorie::nomFeminin('Seniors F'));
        self::assertTrue(Categorie::nomFeminin('Féminines'));
        self::assertFalse(Categorie::nomFeminin('U15 Futsal'));

        // Joueur : ancien club et autorisations.
        $crawler = $client->request('GET', '/joueurs/' . $joueur->getId() . '/modifier');
        $form = $crawler->selectButton('Enregistrer')->form();
        $form['joueur[ancienClub]'] = 'AS Cormontreuil';
        $form['joueur[autorisationSoins]']->tick();
        $form['joueur[partirSeul]']->tick();
        $client->submit($form);
        $crawler = $client->followRedirect();
        $fiche = $crawler->filter('main')->text();
        self::assertStringContainsString('Ancien clubAS Cormontreuil', $fiche);
        self::assertStringContainsString('Soins d\'urgenceOui', $fiche);
        self::assertStringContainsString('Repart seulOui', $fiche);

        // Éducateur : diplôme et sa date, permis, équipes ; conducteur du club au même nom.
        $crawler = $client->request('GET', '/encadrement/educateurs/nouveau');
        $form = $crawler->selectButton('Enregistrer')->form();
        $form['intervenant[nom]'] = 'Coach';
        $form['intervenant[prenom]'] = 'Karim';
        $form['intervenant[diplome]'] = 'BMF';
        $form['intervenant[dateDiplome]'] = '2021-06-15';
        $form['intervenant[permis]'] = 'b';
        foreach ($form['intervenant[equipes]'] as $case) {
            if ($case->availableOptionValues() === [(string) $u15->getId()]) {
                $case->tick();
            }
        }
        $client->submit($form);
        $crawler = $client->followRedirect();
        $fiche = $crawler->filter('main')->text();
        self::assertStringContainsString('Équipes U15', $fiche);
        self::assertStringContainsString('DiplômeBMF (obtenu le 15/06/2021)', $fiche);
        self::assertStringContainsString('Permis B · conducteur du club : contrôlé', $fiche);
        $educateur = $em->getRepository(Educateur::class)->findOneBy(['club' => $club]);
        self::assertSame(['U15'], $educateur->getEquipes()->map(fn (Categorie $c) => $c->getNom())->toArray());

        $crawler = $client->request('GET', '/encadrement/educateurs');
        self::assertStringContainsString('U15', $crawler->filter('.carte.personne')->text());
    }
}
