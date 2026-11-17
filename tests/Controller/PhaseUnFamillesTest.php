<?php

namespace App\Tests\Controller;

use App\Entity\Categorie;
use App\Entity\Club;
use App\Entity\Convocation;
use App\Entity\Joueur;
use App\Entity\Rencontre;
use App\Entity\Utilisateur;
use App\Service\CreationClub;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Mime\Email;

/**
 * Fin de la phase 1 : déplacement d'un match (car, départ) affiché dans la convocation (page, message du groupe,
 * e-mail, espace famille) ; calendrier du mois dans l'espace famille, avec le match où l'enfant est convoqué.
 */
class PhaseUnFamillesTest extends WebTestCase
{
    private ?int $clubId = null;

    protected function tearDown(): void
    {
        if ($this->clubId) {
            $connexion = static::getContainer()->get(EntityManagerInterface::class)->getConnection();
            foreach (['deplacement', 'convocation', 'rencontre', 'entrainement', 'creneau_entrainement', 'utilisateur', 'joueur', 'categorie'] as $table) {
                $connexion->executeStatement("DELETE FROM $table WHERE club_id = ?", [$this->clubId]);
            }
            $connexion->executeStatement('DELETE FROM club WHERE id = ?', [$this->clubId]);
        }
        parent::tearDown();
    }

    public function testDeplacementEtCalendrierFamille(): void
    {
        $client = static::createClient();
        $id = bin2hex(random_bytes(4));
        $admin = (new Utilisateur())->setEmail("admin-$id@exemple.test")->setNom('Test')->setPrenom('Admin');
        $club = (new Club())->setNom("Club Test $id");
        static::getContainer()->get(CreationClub::class)->creer($club, $admin, 'motdepasse-admin');
        $this->clubId = $club->getId();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $u15 = $em->getRepository(Categorie::class)->findOneBy(['club' => $club, 'nom' => 'U15']);
        $lea = (new Joueur())->setClub($club)->setCategorie($u15)->setNom('Martin')->setPrenom('Léa')->setEmailParent1("parent-$id@exemple.test");
        $em->persist($lea);
        $parent = (new Utilisateur())->setClub($club)->setFamille(true)->setEmail("parent-$id@exemple.test")->setNom('Martin')->setPrenom('Famille')->setPassword('x')->addJoueur($lea);
        $em->persist($parent);
        // Match à l'extérieur aujourd'hui, convocation de Léa.
        $match = (new Rencontre())->setClub($club)->setCategorie($u15)->setDate(new \DateTimeImmutable('today'))->setHeure('15h00')
            ->setDomicile('FC SEDAN')->setExterieur("CLUB TEST $id")->setLieu('Stade Dugauguez');
        $em->persist($match);
        $convocation = (new Convocation($match))->setClub($club)->setJoueurs([$lea])->setRdvHeure('12h00');
        foreach ($convocation->getConvoques() as $c) {
            $c->setClub($club);
        }
        $em->persist($convocation);
        $em->flush();
        $client->loginUser($admin);

        // Depuis la convocation : noter le déplacement (car, départ 12h30 du parking du stade).
        $crawler = $client->request('GET', '/matchs/' . $match->getId() . '/convocation');
        self::assertSelectorTextContains('#deplacement', 'Aucun déplacement noté');
        $crawler = $client->click($crawler->selectLink('+ Noter le déplacement')->link());
        $client->submit($crawler->selectButton('Enregistrer')->form([
            'deplacement[moyen]' => 'Car de société',
            'deplacement[heureDepart]' => '12h30',
            'deplacement[lieuDepart]' => 'parking du stade',
        ]));
        self::assertResponseRedirects('/matchs/' . $match->getId() . '/convocation#deplacement');
        $crawler = $client->followRedirect();
        self::assertSelectorTextContains('#deplacement', 'Car de société · départ 12h30, parking du stade');
        self::assertStringContainsString('Déplacement : Car de société · départ 12h30, parking du stade', $crawler->filter('[data-copier-texte-value]')->attr('data-copier-texte-value'));

        // Envoi : l'e-mail de convocation donne le déplacement.
        $client->submit($crawler->selectButton('Envoyer aux parents')->form());
        $mails = array_values(array_filter(self::getMailerMessages(), fn ($m) => $m instanceof Email && str_contains((string) $m->getTextBody(), 'convoqué')));
        self::assertNotEmpty($mails);
        self::assertStringContainsString('Déplacement : Car de société · départ 12h30, parking du stade.', end($mails)->getTextBody());

        // Espace famille : la convocation avec le déplacement, et le calendrier du mois.
        $client->loginUser($parent);
        $crawler = $client->request('GET', '/espace');
        self::assertStringContainsString('🚌 Car de société · départ 12h30, parking du stade', $crawler->filter('.convocation-famille')->text());
        $crawler = $client->click($crawler->selectLink('📅 Calendrier du mois')->link());
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('nav a[href="/espace/calendrier"].actif');
        $cal = $crawler->filter('.cal-match.convoque');
        self::assertCount(1, $cal);
        self::assertStringContainsString('FC SEDAN', $cal->text());
        self::assertStringContainsString('convoqué', $cal->text());
        self::assertSame('span', $cal->nodeName(), 'pas de lien vers les pages de l\'encadrement');
        self::assertCount(0, $crawler->filter('.calendrier a'));

        // Mois suivant : navigation.
        $crawler = $client->click($crawler->filter('a[aria-label="Mois suivant"]')->link());
        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('.cal-match.convoque'));
    }
}
