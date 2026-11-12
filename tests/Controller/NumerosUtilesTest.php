<?php

namespace App\Tests\Controller;

use App\Entity\Club;
use App\Entity\Dirigeant;
use App\Entity\PosteOrganigramme;
use App\Entity\Utilisateur;
use App\Service\CreationClub;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Numéros utiles : bureau repris de l'organigramme avec le téléphone de la fiche, numéro saisi par le club,
 * numéros d'urgence ajoutés en un clic (une seule fois), modification et suppression.
 */
class NumerosUtilesTest extends WebTestCase
{
    private ?int $clubId = null;

    protected function tearDown(): void
    {
        if ($this->clubId) {
            $connexion = static::getContainer()->get(EntityManagerInterface::class)->getConnection();
            $connexion->executeStatement('UPDATE poste_organigramme SET parent_id = NULL WHERE club_id = ?', [$this->clubId]);
            foreach (['contact_utile', 'poste_organigramme', 'dirigeant', 'utilisateur', 'categorie'] as $table) {
                $connexion->executeStatement("DELETE FROM $table WHERE club_id = ?", [$this->clubId]);
            }
            $connexion->executeStatement('DELETE FROM club WHERE id = ?', [$this->clubId]);
        }
        parent::tearDown();
    }

    public function testNumerosUtiles(): void
    {
        $client = static::createClient();
        $id = bin2hex(random_bytes(4));
        $admin = (new Utilisateur())->setEmail("admin-$id@exemple.test")->setNom('Test')->setPrenom('Admin');
        $club = (new Club())->setNom("Club Test $id");
        static::getContainer()->get(CreationClub::class)->creer($club, $admin, 'motdepasse-admin');
        $this->clubId = $club->getId();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $president = (new Dirigeant())->setClub($club)->setNom('Martin')->setPrenom('Paul')->setTelPortable1('06 10 20 30 40');
        $em->persist($president);
        $em->flush();
        $poste = (new PosteOrganigramme())->setClub($club)->setTitre('Président')->setPersonne('dirigeants:' . $president->getId());
        $em->persist($poste);
        $em->persist((new PosteOrganigramme())->setClub($club)->setTitre('Vice-président')->setParent($poste)->setNomLibre('Jeanne Leroy'));
        $em->persist((new PosteOrganigramme())->setClub($club)->setTitre('Secrétaire')->setParent($poste));
        $em->flush();
        $client->loginUser($admin);

        // Bureau : postes pourvus seulement, avec le téléphone de la fiche.
        $crawler = $client->request('GET', '/numeros-utiles');
        self::assertResponseIsSuccessful();
        $club = $crawler->filter('#club')->text();
        self::assertStringContainsString('Président Paul MARTIN 06 10 20 30 40', $club);
        self::assertStringContainsString('Vice-président Jeanne Leroy', $club);
        self::assertStringNotContainsString('Secrétaire', $club);
        self::assertSame('tel:0610203040', $crawler->filter('#club a[href^="tel:"]')->attr('href'));

        // Nouveau numéro, rangé dans sa rubrique.
        $crawler = $client->request('GET', '/numeros-utiles/nouveau?rubrique=installations');
        $client->submit($crawler->selectButton('Enregistrer')->form([
            'contact_utile[libelle]' => 'Stade municipal – gardien',
            'contact_utile[nom]' => 'M. Durand',
            'contact_utile[telephone]' => '03 26 00 11 22',
            'contact_utile[note]' => 'Portail : code 1234',
        ]));
        self::assertResponseRedirects('/numeros-utiles#installations');
        $crawler = $client->followRedirect();
        self::assertStringContainsString('Stade municipal – gardien M. Durand 03 26 00 11 22 Portail : code 1234', $crawler->filter('#installations')->text());

        // Numéros d'urgence : ajoutés une fois, le bouton disparaît ensuite.
        $client->submit($crawler->selectButton('Ajouter les numéros d\'urgence (15, 17, 18, 112, 114)')->form());
        $crawler = $client->followRedirect();
        self::assertCount(5, $crawler->filter('#urgences tr'));
        self::assertStringContainsString('SAMU 15', $crawler->filter('#urgences')->text());
        self::assertCount(0, $crawler->filter('button:contains("numéros d\'urgence")'));

        // Modification puis suppression.
        $crawler = $client->click($crawler->filter('#installations a:contains("Modifier")')->link());
        $client->submit($crawler->selectButton('Enregistrer')->form(['contact_utile[telephone2]' => '06 99 88 77 66']));
        $crawler = $client->followRedirect();
        self::assertStringContainsString('06 99 88 77 66', $crawler->filter('#installations')->text());
        $crawler = $client->click($crawler->filter('#installations a:contains("Modifier")')->link());
        $client->submit($crawler->filter('form[data-confirmation]')->form());
        $crawler = $client->followRedirect();
        self::assertCount(0, $crawler->filter('#installations'));
    }
}
