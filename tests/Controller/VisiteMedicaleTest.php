<?php

namespace App\Tests\Controller;

use App\Entity\Categorie;
use App\Entity\Club;
use App\Entity\Joueur;
use App\Entity\Mesure;
use App\Entity\Utilisateur;
use App\Service\CreationClub;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Visite médicale (poids, taille...) : réservée au droit Infirmerie. Un éducateur qui peut modifier
 * la fiche ne la voit pas et ne peut pas la saisir ; il garde les tests physiques. Club fictif supprimé à la fin.
 */
class VisiteMedicaleTest extends WebTestCase
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
            foreach (['rencontre', 'joueur', 'club'] as $table) {
                $connexion->executeStatement("DELETE FROM $table WHERE " . ('club' === $table ? 'id' : 'club_id') . ' = ?', [$this->clubId]);
            }
        }
        parent::tearDown();
    }

    public function testReserveeALInfirmerie(): void
    {
        $id = bin2hex(random_bytes(4));
        $admin = (new Utilisateur())->setEmail("admin-$id@exemple.test")->setNom('Test')->setPrenom('Admin');
        $club = (new Club())->setNom("Club Test $id");
        static::getContainer()->get(CreationClub::class)->creer($club, $admin, 'motdepasse-admin');
        $this->clubId = $club->getId();

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $categorie = $em->getRepository(Categorie::class)->findOneBy(['club' => $club], ['ordre' => 'ASC']);
        $joueur = (new Joueur())->setClub($club)->setCategorie($categorie)->setNom('Martin')->setPrenom('Léo');
        $em->persist($joueur);
        $em->persist((new Mesure())->setClub($club)->setJoueur($joueur)->setDate(new \DateTimeImmutable('2026-09-01'))->setPoids(41.5));
        $educateur = (new Utilisateur())->setClub($club)->setEmail("educ-$id@exemple.test")->setNom('Durand')->setPrenom('Educ')
            ->setRolesAttribues([Categorie::SECTEURS[$categorie->getSecteur()]['role']]);
        $em->persist($educateur);
        $em->flush();
        $mesure = $em->getRepository(Mesure::class)->findOneBy(['joueur' => $joueur]);

        // Infirmerie (l'administrateur a tous les droits) : bloc visible, avec la mesure.
        $this->client->loginUser($admin);
        $crawler = $this->client->request('GET', '/joueurs/' . $joueur->getId());
        self::assertSelectorTextContains('#visite-medicale', 'Visite médicale');
        self::assertSelectorTextContains('main', '41,5 kg');
        self::assertCount(0, $crawler->filter('a[href="#physique"]:contains("Mesures")'));

        // Éducateur du secteur : il modifie la fiche et saisit les tests, mais ne voit pas la visite médicale.
        $this->client->loginUser($educateur);
        $this->client->request('GET', '/joueurs/' . $joueur->getId());
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('a[href="/joueurs/' . $joueur->getId() . '/tests/nouveau"]');
        self::assertSelectorNotExists('#visite-medicale');
        self::assertSelectorTextNotContains('main', '41,5 kg');
        $this->client->request('GET', '/joueurs/' . $joueur->getId() . '/mesures/nouvelle');
        self::assertResponseStatusCodeSame(403);
        $this->client->request('POST', '/mesures/' . $mesure->getId() . '/supprimer', ['_token' => 'x']);
        self::assertResponseStatusCodeSame(403);
        $this->client->request('GET', '/joueurs/' . $joueur->getId() . '/tests/nouveau');
        self::assertResponseIsSuccessful();
    }
}
