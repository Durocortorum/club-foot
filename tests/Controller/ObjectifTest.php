<?php

namespace App\Tests\Controller;

use App\Entity\Categorie;
use App\Entity\Club;
use App\Entity\Joueur;
use App\Entity\ObjectifJoueur;
use App\Entity\Utilisateur;
use App\Service\CreationClub;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Objectifs individuels de progression : objectif créé puis mis à jour (historique), observations partagées ou
 * réservées à l'encadrement, suivi visible par la famille du joueur et par elle seule. Club supprimé à la fin.
 */
class ObjectifTest extends WebTestCase
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
            foreach (['observation_joueur', 'objectif_joueur', 'joueur', 'utilisateur', 'club'] as $table) {
                $connexion->executeStatement("DELETE FROM $table WHERE " . ('club' === $table ? 'id' : 'club_id') . ' = ?', [$this->clubId]);
            }
        }
        parent::tearDown();
    }

    public function testObjectifsEtObservations(): void
    {
        $id = bin2hex(random_bytes(4));
        $admin = (new Utilisateur())->setEmail("admin-$id@exemple.test")->setNom('Test')->setPrenom('Coach');
        $club = (new Club())->setNom("Club Test $id");
        static::getContainer()->get(CreationClub::class)->creer($club, $admin, 'motdepasse-admin');
        $this->clubId = $club->getId();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $u15 = $em->getRepository(Categorie::class)->findOneBy(['club' => $club, 'nom' => 'U15']);
        $leo = (new Joueur())->setClub($club)->setCategorie($u15)->setNom('Martin')->setPrenom('Léo')->setDateNaissance(new \DateTimeImmutable('-14 years'));
        $tom = (new Joueur())->setClub($club)->setCategorie($u15)->setNom('Durand')->setPrenom('Tom')->setDateNaissance(new \DateTimeImmutable('-14 years'));
        $parentLeo = (new Utilisateur())->setClub($club)->setFamille(true)->setEmail("parent-leo-$id@exemple.test")->setNom('Martin')->setPrenom('Famille')->addJoueur($leo);
        $parentTom = (new Utilisateur())->setClub($club)->setFamille(true)->setEmail("parent-tom-$id@exemple.test")->setNom('Durand')->setPrenom('Famille')->addJoueur($tom);
        foreach ([$leo, $tom, $parentLeo, $parentTom] as $o) {
            $em->persist($o);
        }
        $em->flush();

        $this->client->loginUser($admin);
        $crawler = $this->client->request('GET', '/joueurs/' . $leo->getId());
        $crawler = $this->client->click($crawler->selectLink('🎯 Objectifs')->link());
        self::assertResponseIsSuccessful();
        $form = $crawler->selectButton('Ajouter l\'objectif')->form(['titre' => 'Améliorer ma finition', 'theme' => 'finition', 'progression' => '30']);
        $this->client->submit($form);
        $crawler = $this->client->followRedirect();
        self::assertSelectorTextContains('.objectif', 'Améliorer ma finition');
        self::assertSelectorTextContains('.objectif-pct', '30 %');

        // Mise à jour du pourcentage (curseur), puis observations : une partagée avec vidéo, une pour l'encadrement.
        $this->client->submit($crawler->filter('.objectif-maj')->form(['progression' => '75']));
        $crawler = $this->client->followRedirect();
        self::assertSelectorTextContains('.objectif-pct', '75 %');
        $form = $crawler->selectButton('Enregistrer l\'observation')->form(['texte' => 'Bonne progression sur la finition.', 'lien_video' => 'www.youtube.com/watch?v=abc']);
        $this->client->submit($form);
        $crawler = $this->client->followRedirect();
        $form = $crawler->selectButton('Enregistrer l\'observation')->form(['texte' => 'Note interne : à surveiller.']);
        $form['partage']->untick();
        $this->client->submit($form);
        $this->client->followRedirect();
        self::assertSelectorTextContains('#observations', 'Note interne');
        self::assertSelectorExists('#observations a[href="https://www.youtube.com/watch?v=abc"]');

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $objectif = $em->getRepository(ObjectifJoueur::class)->findOneBy(['joueur' => $leo->getId()]);
        self::assertSame(75, $objectif->getProgression());
        self::assertSame(75, $objectif->progressionAu(new \DateTimeImmutable('today')));

        // Famille de Léo : résumé dans son espace, puis suivi sans l'observation interne.
        $this->client->loginUser($parentLeo);
        $crawler = $this->client->request('GET', '/espace');
        self::assertSelectorTextContains('.objectif', 'Améliorer ma finition');
        $this->client->click($crawler->selectLink('Voir le suivi et les observations')->link());
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('#observations', 'Bonne progression sur la finition.');
        self::assertSelectorTextNotContains('#observations', 'Note interne');

        // Famille d'un autre joueur : pas d'accès.
        $this->client->loginUser($parentTom);
        $this->client->request('GET', '/espace/joueur/' . $leo->getId() . '/objectifs');
        self::assertResponseStatusCodeSame(404);
    }
}
