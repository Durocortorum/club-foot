<?php

namespace App\Tests\Controller;

use App\Entity\Categorie;
use App\Entity\Club;
use App\Entity\Joueur;
use App\Entity\Participation;
use App\Entity\Presence;
use App\Entity\Rencontre;
use App\Entity\SemainePresence;
use App\Entity\Utilisateur;
use App\Service\CreationClub;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Assiduité et performance d'un joueur : taux de présence, minutes, note moyenne, calendrier des présences, et la
 * tendance entre la présence des semaines d'avant match et la note. Données fictives, club supprimé à la fin.
 */
class AssiduiteTest extends WebTestCase
{
    private ?int $clubId = null;

    protected function tearDown(): void
    {
        if ($this->clubId) {
            $db = static::getContainer()->get(EntityManagerInterface::class)->getConnection();
            $db->executeStatement('SET FOREIGN_KEY_CHECKS = 0');
            foreach (['presence', 'semaine_presence', 'participation', 'rencontre', 'utilisateur', 'joueur', 'categorie'] as $table) {
                $db->executeStatement("DELETE FROM $table WHERE club_id = ?", [$this->clubId]);
            }
            $db->executeStatement('DELETE FROM club WHERE id = ?', [$this->clubId]);
            $db->executeStatement('SET FOREIGN_KEY_CHECKS = 1');
        }
        parent::tearDown();
    }

    public function testAssiduiteEtPerformance(): void
    {
        $client = static::createClient();
        $id = bin2hex(random_bytes(4));
        $admin = (new Utilisateur())->setEmail("admin-$id@exemple.test")->setNom('Test')->setPrenom('Coach');
        $club = (new Club())->setNom("FC Assiduite $id");
        static::getContainer()->get(CreationClub::class)->creer($club, $admin, 'motdepasse-admin');
        $this->clubId = $club->getId();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $u15 = $em->getRepository(Categorie::class)->findOneBy(['club' => $club, 'nom' => 'U15']);
        $tom = (new Joueur())->setClub($club)->setCategorie($u15)->setNom('Petit')->setPrenom('Tom');
        $em->persist($tom);

        // Six semaines d'entraînement (mardi et jeudi), chacune suivie d'un match le samedi : plus il est venu,
        // meilleure est la note (de 2 absences / 4 séances à toutes les séances).
        [$debut] = Rencontre::bornesSaison(Rencontre::saisonDe(new \DateTimeImmutable('today')));
        $lundi = (new \DateTimeImmutable($debut->format('Y') . '-09-01'))->modify('monday this week')->modify('+1 week');
        $presences = [['Malade', 'U15'], ['U15', 'U15'], ['Absent', 'U15'], ['U15', 'U15'], ['Blessé', 'U15'], ['U15', 'U15']];
        $notes = [5.0, 7.5, 5.5, 8.0, 5.0, 7.0];
        foreach ($presences as $i => [$mardi, $jeudi]) {
            $semaine = (new SemainePresence())->setClub($club)->setLundi($lundi)->setCategorie($u15);
            $semaine->addPresence((new Presence())->setClub($club)->setJoueur($tom)->setCreneaux(['mar_a' => $mardi, 'jeu_a' => $jeudi]));
            $em->persist($semaine);
            $match = (new Rencontre())->setClub($club)->setCategorie($u15)->setCompetition('Championnat')->setDate($lundi->modify('+5 days'))
                ->setDomicile("FC ASSIDUITE $id")->setExterieur('AS Adverse ' . $i)->setButsDomicile(1)->setButsExterieur(0);
            $match->addParticipation((new Participation())->setClub($club)->setJoueur($tom)->setTitulaire(true)->setTempsJeu(60)->setNote($notes[$i]));
            $em->persist($match);
            $lundi = $lundi->modify('+1 week');
        }
        $em->flush();
        $client->loginUser($admin);

        $crawler = $client->request('GET', '/joueurs/' . $tom->getId());
        $crawler = $client->click($crawler->selectLink('📈 Assiduité et performance')->link());
        self::assertResponseIsSuccessful();
        $tuiles = $crawler->filter('.ass-tuiles .tuile')->each(fn ($t) => $t->text());
        self::assertStringContainsString('75 %', $tuiles[0], '9 présences sur 12 séances');
        self::assertStringContainsString('9 / 12 séances', $tuiles[0]);
        self::assertStringContainsString('360 min', $tuiles[1]);
        self::assertStringContainsString('6,3', $tuiles[2], 'note moyenne');
        // Calendrier : 9 jours présents, 3 absents (avec leur motif au survol).
        self::assertCount(9, $crawler->filter('.ass-jour.present'));
        self::assertCount(3, $crawler->filter('.ass-jour.absent'));
        $motifs = implode(' | ', $crawler->filter('.ass-jour.absent')->each(fn ($j) => $j->attr('title')));
        foreach (['Malade', 'Absent', 'Blessé'] as $motif) {
            self::assertStringContainsString($motif, $motifs);
        }
        // Tendance : présence des 28 jours d'avant et note, nettement positive.
        self::assertSelectorTextContains('.ass-lecture.positive', 'Tendance forte');
        self::assertCount(6, $crawler->filter('.ass-relation circle'));
        self::assertSelectorTextContains('.ass-avertissement', 'sans en déduire automatiquement une cause');
    }
}
