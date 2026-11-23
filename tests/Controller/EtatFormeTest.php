<?php

namespace App\Tests\Controller;

use App\Entity\Categorie;
use App\Entity\Club;
use App\Entity\EtatForme;
use App\Entity\Joueur;
use App\Entity\Utilisateur;
use App\Service\CreationClub;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * État de forme du jour : la famille renseigne fatigue, sommeil, douleurs et motivation (une fois par jour,
 * corrigeable), l'éducateur voit le groupe (en forme, à surveiller, fatigué, sans réponse). Club supprimé à la fin.
 */
class EtatFormeTest extends WebTestCase
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
            foreach (['etat_forme', 'joueur', 'utilisateur', 'club'] as $table) {
                $connexion->executeStatement("DELETE FROM $table WHERE " . ('club' === $table ? 'id' : 'club_id') . ' = ?', [$this->clubId]);
            }
        }
        parent::tearDown();
    }

    public function testEtatDeFormeDuGroupe(): void
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
        $parent = (new Utilisateur())->setClub($club)->setFamille(true)->setEmail("parent-$id@exemple.test")->setNom('Martin')->setPrenom('Sophie')->addJoueur($leo);
        foreach ([$leo, $tom, $parent] as $o) {
            $em->persist($o);
        }
        $em->flush();

        // La famille de Léo renseigne son état : fatigue 8 (rouge) → « Fatigué » ; puis corrige le jour même.
        $this->client->loginUser($parent);
        $crawler = $this->client->request('GET', '/espace');
        $form = $crawler->filter('form[action$="/espace/etat-forme/' . $leo->getId() . '"]')->form(['fatigue' => '8', 'sommeil' => '7', 'douleurs' => '1', 'motivation' => '8', 'commentaire' => 'Petite nuit']);
        $this->client->submit($form);
        $this->client->followRedirect();
        self::assertSelectorTextContains('#etat-forme-' . $leo->getId(), 'Fatigué');
        $crawler = $this->client->getCrawler();
        $this->client->submit($crawler->filter('form[action$="/espace/etat-forme/' . $leo->getId() . '"]')->form(['fatigue' => '3']));
        $this->client->followRedirect();
        self::assertSelectorTextContains('#etat-forme-' . $leo->getId(), 'En forme');
        $em = static::getContainer()->get(EntityManagerInterface::class);
        self::assertCount(1, $em->getRepository(EtatForme::class)->findBy(['joueur' => $leo->getId()]));

        // Pas pour l'enfant d'un autre.
        $this->client->request('POST', '/espace/etat-forme/' . $tom->getId(), ['_token' => 'x', 'fatigue' => 5]);
        self::assertResponseStatusCodeSame(403);

        // L'éducateur : 2 joueurs, 1 en forme, 1 sans réponse, le commentaire visible.
        $this->client->loginUser($admin);
        $this->client->request('GET', '/etat-forme?categorie=' . $u15->getId());
        self::assertResponseIsSuccessful();
        $tuiles = $this->client->getCrawler()->filter('.etat-forme-tuiles .tuile-valeur')->each(fn ($n) => trim($n->text()));
        self::assertSame(['2', '1', '0', '0', '1'], $tuiles);
        self::assertSelectorTextContains('.tableau-etat-forme', 'Petite nuit');
    }
}
