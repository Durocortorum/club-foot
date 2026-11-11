<?php

namespace App\Tests\Controller;

use App\Entity\Categorie;
use App\Entity\Club;
use App\Entity\Entrainement;
use App\Entity\Joueur;
use App\Entity\Rencontre;
use App\Entity\Ressenti;
use App\Entity\Utilisateur;
use App\Service\CreationClub;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Autoévaluation : le joueur note l'effort après l'entraînement et le match depuis son espace ;
 * l'éducateur voit les notes, la moyenne de chaque séance et la charge de la semaine en forte hausse.
 */
class RessentiTest extends WebTestCase
{
    private ?int $clubId = null;

    protected function tearDown(): void
    {
        if ($this->clubId) {
            $connexion = static::getContainer()->get(EntityManagerInterface::class)->getConnection();
            foreach (['ressenti', 'entrainement', 'rencontre', 'utilisateur', 'joueur', 'categorie'] as $table) {
                $connexion->executeStatement("DELETE FROM $table WHERE club_id = ?", [$this->clubId]);
            }
            $connexion->executeStatement('DELETE FROM club WHERE id = ?', [$this->clubId]);
        }
        parent::tearDown();
    }

    public function testAutoevaluation(): void
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
        $famille = (new Utilisateur())->setClub($club)->setFamille(true)->setEmail("famille-$id@exemple.test")->setNom('Petit')->setPrenom('Famille')->setPassword('x')->addJoueur($tom);
        $aujourdhui = new \DateTimeImmutable('today');
        $entrainement = (new Entrainement())->setClub($club)->setCategorie($u15)->setDate($aujourdhui);
        $match = (new Rencontre())->setClub($club)->setCategorie($u15)->setDate($aujourdhui)->setCompetition('Championnat')->setDomicile("CLUB TEST $id")->setExterieur('FC METZ');
        // La semaine précédente : un entraînement tranquille (3/10 pendant 60 min, charge 180).
        $passe = (new Entrainement())->setClub($club)->setCategorie($u15)->setDate($aujourdhui->modify('monday this week')->modify('-3 days'));
        foreach ([$tom, $famille, $entrainement, $match, $passe] as $o) {
            $em->persist($o);
        }
        $em->persist((new Ressenti($tom, $passe, 3, 60))->setClub($club));
        $em->flush();

        // Espace famille : l'entraînement et le match du jour sont à noter.
        $client->loginUser($famille);
        $crawler = $client->request('GET', '/espace');
        self::assertCount(2, $crawler->filter('form.ressenti-saisie'));
        self::assertCount(10, $crawler->filter('form.ressenti-saisie')->first()->filter('.echelle-rpe input[type=radio]'));

        // Entraînement : 8/10, durée non précisée (90 min) ; match : 6/10 pendant 60 min, avec un commentaire.
        $form = $crawler->filter('form.ressenti-saisie input[value="e-' . $entrainement->getId() . '"]')->closest('form')->form();
        $form['rpe']->select('8');
        $client->submit($form);
        $crawler = $client->followRedirect();
        self::assertStringContainsString('Effort noté 8/10 (très dur +) pour Tom', $crawler->filter('.flash-succes')->text());
        self::assertCount(1, $crawler->filter('form.ressenti-saisie'), 'l\'entraînement noté ne revient plus');
        $form = $crawler->filter('form.ressenti-saisie')->form();
        $form['rpe']->select('6');
        $client->submit($form, ['duree' => '60', 'commentaire' => 'Mal au genou']);
        $crawler = $client->followRedirect();
        self::assertCount(0, $crawler->filter('form.ressenti-saisie'));
        self::assertCount(3, $crawler->filter('.pastille-rpe'), 'les ressentis des 14 derniers jours sont rappelés');

        // L'éducateur : notes de Tom, moyenne des séances, charge 8×90 + 6×60 = 1080, soit 6 fois la semaine précédente.
        $client->loginUser($admin);
        $crawler = $client->request('GET', '/ressentis?categorie=' . $u15->getId());
        self::assertResponseIsSuccessful();
        $ligne = $crawler->filter('table.tableau-rpe tbody tr')->reduce(fn ($tr) => false !== stripos($tr->text(), 'Petit Tom'));
        self::assertStringContainsString('1080', $ligne->text());
        self::assertStringContainsString('↑ 6,0×', $ligne->text());
        self::assertSame('charge-alerte', $ligne->attr('class'));
        self::assertStringContainsString('Mal au genou', $ligne->filter('.pastille-rpe[title*="genou"]')->attr('title'));
    }
}
