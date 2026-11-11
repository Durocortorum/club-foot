<?php

namespace App\Tests\Controller;

use App\Entity\Categorie;
use App\Entity\Club;
use App\Entity\Joueur;
use App\Entity\TestPhysique;
use App\Entity\Utilisateur;
use App\Service\CreationClub;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Suivi de progression : courbes et évolution d'un joueur (un temps qui baisse est un progrès),
 * tableau de l'équipe avec le meilleur de chaque test.
 */
class ProgressionTest extends WebTestCase
{
    private ?int $clubId = null;

    protected function tearDown(): void
    {
        if ($this->clubId) {
            $connexion = static::getContainer()->get(EntityManagerInterface::class)->getConnection();
            foreach (['test_physique', 'utilisateur', 'joueur', 'categorie'] as $table) {
                $connexion->executeStatement("DELETE FROM $table WHERE club_id = ?", [$this->clubId]);
            }
            $connexion->executeStatement('DELETE FROM club WHERE id = ?', [$this->clubId]);
        }
        parent::tearDown();
    }

    public function testProgression(): void
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
        // Tom : VMA 12 → 13 → 14,5 km/h ; 20 m : 3,6 → 3,4 s. Léo : VMA 15 puis 14,5 (recul).
        foreach ([[$tom, '-8 months', 12, 3.6], [$tom, '-4 months', 13, null], [$tom, '-1 week', 14.5, 3.4], [$leo, '-4 months', 15, 3.3], [$leo, '-1 week', 14.5, 3.5]] as [$j, $quand, $vma, $sprint]) {
            $em->persist((new TestPhysique())->setClub($club)->setJoueur($j)->setDate(new \DateTimeImmutable($quand))->setVma($vma)->setSprint20($sprint));
        }
        $em->flush();
        $client->loginUser($admin);

        // Fiche de Tom → « Voir la progression ».
        $crawler = $client->request('GET', '/joueurs/' . $tom->getId());
        $crawler = $client->click($crawler->selectLink('📈 Voir la progression')->link());
        self::assertResponseIsSuccessful();
        $cartes = $crawler->filter('.progression-serie');
        $vma = $cartes->reduce(fn ($c) => str_contains($c->filter('h3')->text(), 'VMA'));
        self::assertStringContainsString('+2,5 km/h', $vma->filter('.progression-evolution')->text());
        self::assertStringContainsString('mieux', $vma->filter('.progression-evolution')->attr('class'));
        self::assertCount(3, $vma->filter('circle.marque'));
        $sprint = $cartes->reduce(fn ($c) => str_contains($c->filter('h3')->text(), '20 m') && !str_contains($c->filter('h3')->text(), 'fréquence'));
        self::assertStringContainsString('−0,2 s', $sprint->filter('.progression-evolution')->text());
        self::assertStringContainsString('mieux', $sprint->filter('.progression-evolution')->attr('class'), 'un temps qui baisse est un progrès');

        // Équipe : meilleure VMA (★) pour Léo malgré son recul, en rouge.
        $crawler = $client->request('GET', '/progression?categorie=' . $u15->getId());
        self::assertResponseIsSuccessful();
        $lignes = $crawler->filter('table.tableau-progression tbody tr');
        $leoLigne = $lignes->reduce(fn ($tr) => false !== stripos($tr->text(), 'Durand'));
        self::assertStringContainsString('★ 14,5', $leoLigne->text());
        self::assertStringContainsString('moins-bien', $leoLigne->filter('.progression-evolution')->first()->attr('class'));
    }
}
