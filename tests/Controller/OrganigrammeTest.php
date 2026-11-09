<?php

namespace App\Tests\Controller;

use App\Entity\Club;
use App\Entity\Dirigeant;
use App\Entity\PosteOrganigramme;
use App\Entity\Utilisateur;
use App\Service\CreationClub;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Organigramme : modèle type, personne d'un poste (fiche ou nom), ordre, suppression d'une branche, lecture seule.
 */
class OrganigrammeTest extends WebTestCase
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
            // Les enfants d'abord (clé vers le parent), puis les fiches.
            $connexion->executeStatement('UPDATE poste_organigramme SET parent_id = NULL WHERE club_id = ?', [$this->clubId]);
            foreach (['poste_organigramme', 'dirigeant'] as $table) {
                $connexion->executeStatement("DELETE FROM $table WHERE club_id = ?", [$this->clubId]);
            }
            $connexion->executeStatement('DELETE FROM club WHERE id = ?', [$this->clubId]);
        }
        parent::tearDown();
    }

    public function testOrganigrammeDuClub(): void
    {
        $id = bin2hex(random_bytes(4));
        $admin = (new Utilisateur())->setEmail("admin-$id@exemple.test")->setNom('Test')->setPrenom('Admin');
        $club = (new Club())->setNom("Club Test $id");
        static::getContainer()->get(CreationClub::class)->creer($club, $admin, 'motdepasse-admin');
        $this->clubId = $club->getId();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $president = (new Dirigeant())->setClub($club)->setNom('Lefèvre')->setPrenom('Jean')->setFonction('Président')->setTelPortable1('06 12 34 56 78');
        $coach = (new Utilisateur())->setClub($club)->setEmail("coach-$id@exemple.test")->setNom('Durand')->setPrenom('Coach')->setRolesAttribues(['ROLE_FORMATION'])->setPassword('x');
        $em->persist($president);
        $em->persist($coach);
        $em->flush();
        $postes = fn () => $em->getRepository(PosteOrganigramme::class)->findBy(['club' => $this->clubId]);

        // Organigramme type : 9 postes, Président en haut.
        $this->client->loginUser($admin);
        $crawler = $this->client->request('GET', '/encadrement/dirigeants');
        $crawler = $this->client->click($crawler->selectLink('Organigramme')->link());
        $this->client->submit($crawler->selectButton('Créer l\'organigramme type')->form());
        $crawler = $this->client->followRedirect();
        self::assertCount(9, $postes());
        self::assertSame('Président', $crawler->filter('.organigramme > li > .poste .poste-titre')->text());
        self::assertCount(9, $crawler->filter('.poste-vacant'), 'tous les postes à pourvoir');

        // Le président : fiche du dirigeant (nom, téléphone) ; trésorier adjoint : nom saisi, sous le trésorier.
        $poste = fn (string $titre) => $em->getRepository(PosteOrganigramme::class)->findOneBy(['club' => $this->clubId, 'titre' => $titre]);
        $crawler = $this->client->request('GET', '/organigramme/' . $poste('Président')->getId() . '/modifier');
        $this->client->submit($crawler->selectButton('Enregistrer')->form(['poste_organigramme[personne]' => 'dirigeants:' . $president->getId()]));
        $crawler = $this->client->request('GET', '/organigramme/nouveau?parent=' . $poste('Trésorier')->getId());
        $this->client->submit($crawler->selectButton('Enregistrer')->form(['poste_organigramme[titre]' => 'Trésorier adjoint', 'poste_organigramme[nomLibre]' => 'Marie Dubois']));
        $crawler = $this->client->followRedirect();
        $racine = $crawler->filter('.organigramme > li > .poste');
        self::assertStringContainsString('Jean LEFÈVRE', $racine->text());
        self::assertStringContainsString('06 12 34 56 78', $racine->text());
        self::assertStringContainsString('Marie Dubois', $crawler->filter('#poste-' . $poste('Trésorier')->getId() . ' + ul')->text());

        // Ordre : le secrétaire passe avant le trésorier.
        $titres = fn ($c) => $c->filter('.organigramme > li > ul > li > .poste .poste-titre')->each(fn ($n) => $n->text());
        self::assertSame(['Vice-président', 'Trésorier', 'Secrétaire', 'Responsable technique'], $titres($crawler));
        $this->client->submit($crawler->filter('#poste-' . $poste('Secrétaire')->getId())->selectButton('←')->form());
        $crawler = $this->client->followRedirect();
        self::assertSame(['Vice-président', 'Secrétaire', 'Trésorier', 'Responsable technique'], $titres($crawler));

        // Suppression d'une branche : le responsable technique et ses 4 responsables de secteur.
        $crawler = $this->client->request('GET', '/organigramme/' . $poste('Responsable technique')->getId() . '/modifier');
        $this->client->submit($crawler->filter('form[action$="/supprimer"]')->form());
        $em->clear();
        self::assertCount(5, $postes());

        // L'éducateur consulte, sans boutons de modification ; il ne peut pas modifier.
        $this->client->loginUser($coach);
        $crawler = $this->client->request('GET', '/organigramme');
        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('.poste-actions'));
        $this->client->request('GET', '/organigramme/nouveau');
        self::assertResponseStatusCodeSame(403);
    }
}
