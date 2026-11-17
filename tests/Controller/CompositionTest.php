<?php

namespace App\Tests\Controller;

use App\Entity\Categorie;
use App\Entity\Club;
use App\Entity\Joueur;
use App\Entity\Participation;
use App\Entity\Rencontre;
use App\Entity\Utilisateur;
use App\Service\CreationClub;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Composition sur le terrain : places d'après le système et les postes, déplacement enregistré, retour au système.
 */
class CompositionTest extends WebTestCase
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
            foreach (['participation', 'rencontre', 'joueur'] as $table) {
                $connexion->executeStatement("DELETE FROM $table WHERE club_id = ?", [$this->clubId]);
            }
            $connexion->executeStatement('DELETE FROM club WHERE id = ?', [$this->clubId]);
        }
        parent::tearDown();
    }

    public function testPlacerEtDeplacerLesJoueurs(): void
    {
        $id = bin2hex(random_bytes(4));
        $admin = (new Utilisateur())->setEmail("admin-$id@exemple.test")->setNom('Test')->setPrenom('Coach');
        $club = (new Club())->setNom("Club Test $id");
        static::getContainer()->get(CreationClub::class)->creer($club, $admin, 'motdepasse-admin');
        $this->clubId = $club->getId();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $equipe = $em->getRepository(Categorie::class)->findOneBy(['club' => $club], ['ordre' => 'ASC']);

        $match = (new Rencontre())->setClub($club)->setCategorie($equipe)->setDate(new \DateTimeImmutable('today'))
            ->setDomicile("CLUB TEST $id")->setExterieur('FC METZ')->setSysteme('4 3 3');
        $postes = [
            1 => 'Gardien', 2 => 'Latéral droit', 3 => 'Latéral gauche', 4 => 'Défenseur central droit', 5 => 'Défenseur central gauche',
            6 => 'Milieu défensif', 8 => 'Milieu relayeur droit', 10 => 'Milieu relayeur gauche', 7 => 'Attaquant', 9 => 'Attaquant', 11 => 'Attaquant',
            12 => null, 14 => 'Non utilisé',
        ];
        $joueurs = [];
        foreach ($postes as $numero => $poste) {
            $j = (new Joueur())->setClub($club)->setCategorie($equipe)->setNom("Joueur$numero")->setPrenom('Test');
            $em->persist($j);
            $joueurs[$numero] = $j;
            $match->addParticipation((new Participation())->setClub($club)->setJoueur($j)->setNumero($numero)->setPoste($poste));
        }
        $em->persist($match);
        $em->flush();

        $this->client->loginUser($admin);
        $crawler = $this->client->request('GET', '/matchs/' . $match->getId());
        $crawler = $this->client->click($crawler->selectLink('Placer les joueurs')->link());
        self::assertResponseIsSuccessful();

        // 11 titulaires sur le terrain, 2 remplaçants sur le banc.
        self::assertCount(11, $crawler->filter('.terrain-compo .pion'));
        self::assertCount(2, $crawler->filter('.banc .banc-joueur'));
        $place = function ($crawler, int $numero) use ($joueurs): array {
            preg_match('/left: ([\d.]+)%; top: ([\d.]+)%/', $crawler->filter('.pion[data-joueur="' . $joueurs[$numero]->getId() . '"]')->attr('style'), $m);

            return [(float) $m[1], 100 - (float) $m[2]];
        };
        // Gardien en bas au centre, latéral droit à droite de la défense, attaquants devant, milieu défensif au centre.
        self::assertSame([50.0, 6.0], $place($crawler, 1));
        [$x2, $y2] = $place($crawler, 2);
        self::assertGreaterThan(70, $x2);
        self::assertSame(22.0, $y2);
        self::assertLessThan(30, $place($crawler, 3)[0]);
        self::assertSame(82.0, $place($crawler, 9)[1]);
        self::assertSame([50.0, 52.0], $place($crawler, 6));

        // Déplacement enregistré : le n°9 recule, les autres gardent leur place.
        $form = $crawler->selectButton('Enregistrer la composition')->form();
        $form['placements'] = json_encode([$joueurs[9]->getId() => [40, 70], $joueurs[1]->getId() => [50, 6]]);
        $this->client->submit($form);
        $crawler = $this->client->followRedirect();
        self::assertSelectorTextContains('.flash-succes', 'Composition enregistrée');
        self::assertSame([40.0, 70.0], $place($crawler, 9));
        $crawler = $this->client->request('GET', '/matchs/' . $match->getId());
        self::assertSame([40.0, 70.0], $place($crawler, 9), 'même place sur la page du match');

        // Changement de système et retour aux places du système.
        $crawler = $this->client->request('GET', '/matchs/' . $match->getId() . '/composition');
        $this->client->submit($crawler->selectButton('Replacer selon le système')->form(['systeme' => '4 4 2']));
        $crawler = $this->client->followRedirect();
        self::assertSelectorTextContains('.flash-succes', 'Joueurs replacés selon le système 4 4 2');
        self::assertSame(82.0, $place($crawler, 9)[1]);
        $em->clear();
        self::assertSame([], $em->find(Rencontre::class, $match->getId())->getPlacements());
    }

    /** Case « Titulaire » : un titulaire peut porter le n° 23, un n° 11 peut commencer sur le banc. */
    public function testTitulaireAvecUnGrandNumero(): void
    {
        $id = bin2hex(random_bytes(4));
        $admin = (new Utilisateur())->setEmail("admin-$id@exemple.test")->setNom('Test')->setPrenom('Coach');
        $club = (new Club())->setNom("Club Test $id");
        static::getContainer()->get(CreationClub::class)->creer($club, $admin, 'motdepasse-admin');
        $this->clubId = $club->getId();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $equipe = $em->getRepository(Categorie::class)->findOneBy(['club' => $club], ['ordre' => 'ASC']);
        $match = (new Rencontre())->setClub($club)->setCategorie($equipe)->setDate(new \DateTimeImmutable('today'))
            ->setDomicile("CLUB TEST $id")->setExterieur('FC METZ');
        $participations = [];
        foreach ([1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 23] as $numero) {
            $j = (new Joueur())->setClub($club)->setCategorie($equipe)->setNom("Joueur$numero")->setPrenom('Test');
            $em->persist($j);
            $p = (new Participation())->setClub($club)->setJoueur($j)->setNumero($numero);
            self::assertSame($numero <= 11, $p->getTitulaire(), "n° $numero : case cochée d'office jusqu'à 11");
            $match->addParticipation($participations[$numero] = $p);
        }
        // Le n° 23 commence le match, le n° 11 sur le banc.
        $participations[23]->setTitulaire(true);
        $participations[11]->setTitulaire(false);
        $match->calculerTempsJeu();
        $em->persist($match);
        $em->flush();
        $duree = $equipe->getDureeMatch() ?: 90;
        self::assertSame([$duree, 0], [$participations[23]->getTempsJeu(), $participations[11]->getTempsJeu()]);

        $this->client->loginUser($admin);
        $crawler = $this->client->request('GET', '/matchs/' . $match->getId() . '/composition');
        self::assertCount(11, $crawler->filter('.terrain-compo .pion'));
        self::assertCount(1, $crawler->filter('.terrain-compo .pion[data-joueur="' . $participations[23]->getJoueur()->getId() . '"]'), 'n° 23 sur le terrain');
        self::assertStringContainsString('JOUEUR11', $crawler->filter('.banc')->text(), 'n° 11 sur le banc');

        // Jamais plus de titulaires que de joueurs sur le terrain : un 12e coché est refusé ; une ancienne feuille
        // déjà au-dessus (12 avant) reste enregistrable.
        self::assertSame(11, $match->setSysteme('4 4 2')->joueursSurLeTerrain());
        $participations[11]->setTitulaire(true);
        self::assertStringContainsString('Trop de titulaires : 12 cochés pour 11', (string) $match->erreurTitulaires(11));
        self::assertNull($match->erreurTitulaires(12));
        // Système de foot à 8 : 8 joueurs sur le terrain.
        self::assertSame(8, $match->setSysteme('3 3 1')->joueursSurLeTerrain());
    }
}
