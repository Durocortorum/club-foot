<?php

namespace App\Tests\Controller;

use App\Entity\Club;
use App\Entity\Rencontre;
use App\Entity\Sponsor;
use App\Entity\Utilisateur;
use App\Service\CreationClub;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Sponsors : fiche, participations par saison, totaux, archives ; réservé à l'administration.
 */
class SponsorTest extends WebTestCase
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
            $connexion->executeStatement('DELETE FROM partenariat_sponsor WHERE club_id = ?', [$this->clubId]);
            $connexion->executeStatement('DELETE FROM sponsor WHERE club_id = ?', [$this->clubId]);
            $connexion->executeStatement('DELETE FROM club WHERE id = ?', [$this->clubId]);
        }
        parent::tearDown();
    }

    public function testSponsorsEtParticipations(): void
    {
        $id = bin2hex(random_bytes(4));
        $admin = (new Utilisateur())->setEmail("admin-$id@exemple.test")->setNom('Test')->setPrenom('Admin');
        $club = (new Club())->setNom("Club Test $id");
        static::getContainer()->get(CreationClub::class)->creer($club, $admin, 'motdepasse-admin');
        $this->clubId = $club->getId();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $coach = (new Utilisateur())->setClub($club)->setEmail("coach-$id@exemple.test")->setNom('Durand')->setPrenom('Coach')->setRolesAttribues(['ROLE_FORMATION'])->setPassword('x');
        $em->persist($coach);
        $em->flush();
        $saison = Rencontre::saisonDe(new \DateTimeImmutable('today'));

        // Fiche sponsor (nom mis en majuscules), puis deux participations de la saison.
        $this->client->loginUser($admin);
        $crawler = $this->client->request('GET', '/sponsors');
        $crawler = $this->client->click($crawler->selectLink('+ Nouveau sponsor')->link());
        $this->client->submit($crawler->selectButton('Enregistrer')->form([
            'sponsor[nom]' => 'Boulangerie Martin', 'sponsor[type]' => 'Entreprise', 'sponsor[contact]' => 'Paul Martin', 'sponsor[telephone]' => '0326000000',
        ]));
        $crawler = $this->client->followRedirect();
        self::assertSelectorTextContains('h1', 'BOULANGERIE MARTIN');
        foreach ([['Argent', '500', 'Subvention annuelle'], ['Maillots / équipements', '1200,50', 'Jeux de maillots U13']] as [$nature, $montant, $detail]) {
            $crawler = $this->client->click($crawler->selectLink('+ Participation')->link());
            $this->client->submit($crawler->selectButton('Enregistrer')->form([
                'partenariat_sponsor[saison]' => $saison, 'partenariat_sponsor[nature]' => $nature,
                'partenariat_sponsor[montant]' => $montant, 'partenariat_sponsor[description]' => $detail,
            ]));
            $crawler = $this->client->followRedirect();
        }
        self::assertSelectorTextContains('section.bloc .titre-page .pastille', 'Total : 1 700,50 €');
        self::assertCount(2, $crawler->filter('section:contains("Participations") tbody tr'));

        // Liste : total de la saison.
        $this->client->request('GET', '/sponsors');
        self::assertSelectorTextContains('.tuiles .tuile', '1 701 €');

        // N'est plus partenaire : sort de la liste, entre dans les archives.
        $sponsor = $em->getRepository(Sponsor::class)->findOneBy(['club' => $this->clubId]);
        $crawler = $this->client->request('GET', '/sponsors/' . $sponsor->getId() . '/modifier');
        $form = $crawler->selectButton('Enregistrer')->form();
        $form['sponsor[ancien]']->tick();
        $this->client->submit($form);
        $this->client->request('GET', '/sponsors');
        self::assertSelectorTextContains('.vide', 'Aucun sponsor');
        $crawler = $this->client->request('GET', '/archives');
        $crawler = $this->client->click($crawler->filter('a.carte-lien:contains("Sponsors")')->link());
        self::assertSelectorTextContains('table', 'BOULANGERIE MARTIN');

        // Un éducateur n'y a pas accès (montants et contrats).
        $this->client->loginUser($coach);
        $this->client->request('GET', '/sponsors');
        self::assertResponseStatusCodeSame(403);
        $crawler = $this->client->request('GET', '/archives');
        self::assertCount(0, $crawler->filter('a.carte-lien:contains("Sponsors")'));
    }
}
