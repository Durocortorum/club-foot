<?php

namespace App\Tests\Controller;

use App\Entity\Categorie;
use App\Entity\Club;
use App\Entity\Dirigeant;
use App\Entity\Educateur;
use App\Entity\Joueur;
use App\Entity\Rencontre;
use App\Entity\Utilisateur;
use App\Service\CreationClub;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Effectifs : nombres par catégorie et par année de naissance (anciens exclus), listing d'une catégorie
 * et export CSV pour Excel.
 */
class EffectifTest extends WebTestCase
{
    private ?int $clubId = null;

    protected function tearDown(): void
    {
        if ($this->clubId) {
            $connexion = static::getContainer()->get(EntityManagerInterface::class)->getConnection();
            foreach (['utilisateur', 'joueur', 'categorie', 'educateur', 'dirigeant'] as $table) {
                $connexion->executeStatement("DELETE FROM $table WHERE club_id = ?", [$this->clubId]);
            }
            $connexion->executeStatement('DELETE FROM club WHERE id = ?', [$this->clubId]);
        }
        parent::tearDown();
    }

    public function testEffectifsEtListing(): void
    {
        $client = static::createClient();
        $id = bin2hex(random_bytes(4));
        $admin = (new Utilisateur())->setEmail("admin-$id@exemple.test")->setNom('Test')->setPrenom('Admin');
        $club = (new Club())->setNom("Club Test $id");
        static::getContainer()->get(CreationClub::class)->creer($club, $admin, 'motdepasse-admin');
        $this->clubId = $club->getId();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $u15 = $em->getRepository(Categorie::class)->findOneBy(['club' => $club, 'nom' => 'U15']);
        $u17 = $em->getRepository(Categorie::class)->findOneBy(['club' => $club, 'nom' => 'U17']);
        // Année d'un U15 cette saison (saison 2026/2027 : nés en 2012).
        $annee = (int) substr(Rencontre::saisonDe(new \DateTimeImmutable('today')), 5, 4) - 15;
        foreach ([
            [$u15, 'Petit', 'Tom', "$annee-03-14", false, '06 11 22 33 44'],
            [$u15, 'Durand', 'Léo', "$annee-09-01", false, null],
            [$u17, 'Martin', 'Noé', "$annee-01-05", false, null],
            [$u15, 'Sansdate', 'Eva', null, false, null],
            [$u15, 'Ancien', 'Paul', "$annee-05-05", true, null],
        ] as [$cat, $nom, $prenom, $ne, $ancien, $tel]) {
            $em->persist((new Joueur())->setClub($club)->setCategorie($cat)->setNom($nom)->setPrenom($prenom)
                ->setDateNaissance($ne ? new \DateTimeImmutable($ne) : null)->setAncien($ancien)->setTelPortable1($tel));
        }
        $em->flush();
        $client->loginUser($admin);

        // Effectifs : 4 actifs (l'ancien est exclu), 3 en U15 dont un sans date, 3 nés la même année.
        $crawler = $client->request('GET', '/joueurs/effectifs');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', '4 joueurs');
        $ligneU15 = $crawler->filter('table.effectifs')->first()->filter('tr')->reduce(fn ($tr) => str_starts_with(trim($tr->text()), 'U15'));
        self::assertStringContainsString('3', $ligneU15->filter('td')->eq(1)->text());
        self::assertStringContainsString('1 sans date de naissance', $ligneU15->text());
        $ligneAnnee = $crawler->filter('table.effectifs')->eq(1)->filter('tbody tr')->first();
        self::assertSame((string) $annee, $ligneAnnee->filter('td')->eq(0)->text());
        self::assertSame('U15', $ligneAnnee->filter('td')->eq(1)->text());
        self::assertSame('3', $ligneAnnee->filter('td')->eq(3)->text());
        self::assertStringContainsString('U15 : 2 · U17 : 1', $ligneAnnee->text());
        $sansDate = $crawler->filter('table.effectifs')->eq(1)->filter('tbody tr')->last();
        self::assertStringContainsString('Date de naissance non renseignée', $sansDate->text());
        self::assertStringContainsString('Eva SANSDATE', $sansDate->text());

        // Listing de la catégorie U15 : actifs seulement, téléphone des parents affiché.
        $crawler = $client->request('GET', '/joueurs/listing?categorie=' . $u15->getId());
        self::assertResponseIsSuccessful();
        self::assertCount(3, $crawler->filter('table.listing tbody tr'));
        self::assertSelectorTextContains('table.listing', '06 11 22 33 44');
        self::assertSelectorTextNotContains('table.listing', 'Ancien');
        self::assertSelectorTextNotContains('table.listing', 'Martin');

        // Export Excel : en-tête, BOM, une ligne par joueur.
        $client->request('GET', '/joueurs/listing?format=csv');
        self::assertResponseIsSuccessful();
        $csv = $client->getResponse()->getContent();
        self::assertStringStartsWith("\xEF\xBB\xBF\"Nom\";\"Prénom\"", $csv);
        self::assertCount(5, array_filter(explode("\r\n", $csv)));
        self::assertStringContainsString('"MARTIN";"Noé";"U17"', $csv);
        self::assertStringContainsString('attachment; filename="listing-tous-les-joueurs-', $client->getResponse()->headers->get('Content-Disposition'));

        // Encadrement : compté sur la page des effectifs, listing par type, de tout l'encadrement ou de tout le club.
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $club = $em->find(Club::class, $this->clubId);
        $em->persist((new Educateur())->setClub($club)->setNom('Coach')->setPrenom('Karim')->setFonction('U15')->setTelPortable1('07 00 00 00 01'));
        $em->persist((new Dirigeant())->setClub($club)->setNom('Président')->setPrenom('Anne')->setFonction('Présidente'));
        $em->persist((new Dirigeant())->setClub($club)->setNom('Parti')->setPrenom('Jean')->setAncien(true));
        $em->flush();
        $crawler = $client->request('GET', '/joueurs/effectifs');
        $encadrement = $crawler->filter('table.effectifs')->eq(2);
        self::assertStringContainsString('Éducateurs 1', $encadrement->text());
        self::assertStringContainsString('Dirigeants 1', $encadrement->text());
        self::assertSame('2', $encadrement->filter('tfoot th')->eq(1)->text());

        $crawler = $client->request('GET', '/joueurs/listing?qui=educateurs');
        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('table.listing tbody tr'));
        self::assertSelectorTextContains('table.listing', '07 00 00 00 01');

        $crawler = $client->request('GET', '/joueurs/listing?qui=encadrement');
        self::assertCount(2, $crawler->filter('table.listing tbody tr'));
        self::assertSelectorTextContains('table.listing', 'Présidente');
        self::assertSelectorTextNotContains('table.listing', 'PARTI');

        $crawler = $client->request('GET', '/joueurs/listing?qui=tous');
        self::assertSelectorTextContains('h1', '6 personnes');

        $client->request('GET', '/joueurs/listing?qui=dirigeants&format=csv');
        $csv = $client->getResponse()->getContent();
        self::assertStringContainsString('"Rôle";"Fonction"', $csv);
        self::assertStringContainsString('"Dirigeant";"Présidente"', $csv);
        self::assertStringContainsString('filename="listing-dirigeants-', $client->getResponse()->headers->get('Content-Disposition'));
    }
}
