<?php

namespace App\Tests\Controller;

use App\Entity\Categorie;
use App\Entity\Club;
use App\Entity\Joueur;
use App\Entity\Sanction;
use App\Entity\Utilisateur;
use App\Service\CreationClub;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Amendes de discipline : montant saisi avec l'incident, totaux de la saison et reste à payer,
 * bouton « Payée », amende sur la fiche du joueur, amendes encaissées dans les recettes des finances.
 */
class AmendesTest extends WebTestCase
{
    private ?int $clubId = null;

    protected function tearDown(): void
    {
        if ($this->clubId) {
            $connexion = static::getContainer()->get(EntityManagerInterface::class)->getConnection();
            foreach (['sanction', 'joueur', 'utilisateur', 'categorie'] as $table) {
                $connexion->executeStatement("DELETE FROM $table WHERE club_id = ?", [$this->clubId]);
            }
            $connexion->executeStatement('DELETE FROM club WHERE id = ?', [$this->clubId]);
        }
        parent::tearDown();
    }

    public function testAmendes(): void
    {
        $client = static::createClient();
        $id = bin2hex(random_bytes(4));
        $admin = (new Utilisateur())->setEmail("admin-$id@exemple.test")->setNom('Test')->setPrenom('Admin');
        $club = (new Club())->setNom("Club Test $id");
        static::getContainer()->get(CreationClub::class)->creer($club, $admin, 'motdepasse-admin');
        $this->clubId = $club->getId();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $u15 = $em->getRepository(Categorie::class)->findOneBy(['club' => $club, 'nom' => 'U15']);
        $tom = (new Joueur())->setClub($club)->setCategorie($u15)->setNom('Petit')->setPrenom('Tom');
        $leo = (new Joueur())->setClub($club)->setCategorie($u15)->setNom('Durand')->setPrenom('Léo');
        $em->persist($tom);
        $em->persist($leo);
        // Amende déjà payée (Léo, 10 €) et incident sans amende.
        $em->persist((new Sanction())->setClub($club)->setJoueur($leo)->setDate(new \DateTimeImmutable('-3 days'))->setMotif('Retard')
            ->setAmende(10)->setAmendePayeeLe(new \DateTimeImmutable('-1 day')));
        $em->persist((new Sanction())->setClub($club)->setJoueur($leo)->setDate(new \DateTimeImmutable('-2 days'))->setMotif('Tenue'));
        $em->flush();
        $client->loginUser($admin);

        // Nouvel incident avec une amende de 20 € (Tom).
        $crawler = $client->request('GET', '/scolarite/discipline/nouvelle?joueur=' . $tom->getId());
        $client->submit($crawler->selectButton('Enregistrer')->form([
            'sanction[motif]' => 'Carton rouge pour contestation',
            'sanction[amende]' => '20',
        ]));
        self::assertResponseRedirects();

        // Page Discipline : totaux de la saison, reste à payer, puis « Payée ».
        $crawler = $client->request('GET', '/scolarite/discipline');
        $tuiles = $crawler->filter('.tuiles.amendes')->text();
        self::assertMatchesRegularExpression('/Amendes \d{4}\/\d{4}\s*30,00 €/u', $tuiles);
        self::assertMatchesRegularExpression('/Encaissées \d{4}\/\d{4}\s*10,00 €/u', $tuiles);
        self::assertStringContainsString('Reste à payer20,00 € 1 amende', $tuiles);
        $crawler = $client->request('GET', '/scolarite/discipline?amendes=a_payer');
        self::assertCount(1, $crawler->filter('table.liste tbody tr'));
        $client->submit($crawler->selectButton('Payée')->form());
        self::assertResponseRedirects('/scolarite/discipline?amendes=a_payer');
        $crawler = $client->followRedirect();
        self::assertStringContainsString('Aucune amende à payer.', $crawler->filter('table.liste')->text());

        // Fiche du joueur et finances.
        $crawler = $client->request('GET', '/joueurs/' . $tom->getId());
        self::assertStringContainsString('20,00 € payée le ' . date('d/m/Y'), $crawler->text());
        $crawler = $client->request('GET', '/finances');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Amendes de discipline (encaissées)', $crawler->text());
        self::assertMatchesRegularExpression('/Amendes de discipline \(encaissées\)\s*30,00 €/u', $crawler->text());
    }
}
