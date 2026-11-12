<?php

namespace App\Tests\Controller;

use App\Entity\But;
use App\Entity\Categorie;
use App\Entity\Club;
use App\Entity\Joueur;
use App\Entity\Rencontre;
use App\Entity\Utilisateur;
use App\Service\CreationClub;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Compte rendu d'un match aux familles : depuis la fiche du match, message proposé à toute l'équipe avec le score,
 * la mi-temps et nos buts (sans le rapport interne) ; envoi noté sur la fiche ; la famille le lit dans son espace.
 */
class CompteRenduMatchTest extends WebTestCase
{
    private ?int $clubId = null;

    protected function tearDown(): void
    {
        if ($this->clubId) {
            $connexion = static::getContainer()->get(EntityManagerInterface::class)->getConnection();
            foreach (['message', 'but', 'rencontre', 'utilisateur', 'joueur', 'categorie'] as $table) {
                $connexion->executeStatement("DELETE FROM $table WHERE club_id = ?", [$this->clubId]);
            }
            $connexion->executeStatement('DELETE FROM club WHERE id = ?', [$this->clubId]);
        }
        parent::tearDown();
    }

    public function testCompteRendu(): void
    {
        $client = static::createClient();
        $id = bin2hex(random_bytes(4));
        $admin = (new Utilisateur())->setEmail("admin-$id@exemple.test")->setNom('Dupont')->setPrenom('Admin');
        $club = (new Club())->setNom("Club Test $id");
        static::getContainer()->get(CreationClub::class)->creer($club, $admin, 'motdepasse-admin');
        $this->clubId = $club->getId();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $u15 = $em->getRepository(Categorie::class)->findOneBy(['club' => $club, 'nom' => 'U15']);
        $lea = (new Joueur())->setClub($club)->setCategorie($u15)->setNom('Martin')->setPrenom('Léa');
        $tom = (new Joueur())->setClub($club)->setCategorie($u15)->setNom('Durand')->setPrenom('Tom');
        $em->persist($lea);
        $em->persist($tom);
        $parent = (new Utilisateur())->setClub($club)->setFamille(true)->setEmail("parent-$id@exemple.test")->setNom('Martin')->setPrenom('Famille')->setPassword('x')->addJoueur($lea);
        $em->persist($parent);
        $match = (new Rencontre())->setClub($club)->setCategorie($u15)->setDate(new \DateTimeImmutable('2026-11-07'))
            ->setDomicile("CLUB TEST $id")->setExterieur('Reims')->setCompetition('Championnat')
            ->setButsDomicile(2)->setButsExterieur(1)->setButsDomicileMiTemps(1)->setButsExterieurMiTemps(0)
            ->setRapport('Défense trop basse, à retravailler.');
        $match->addBut((new But())->setClub($club)->setMinute(12)->setButeur($lea)->setPasseur($tom));
        $match->addBut((new But())->setClub($club)->setMinute(40)->setAdverse(true));
        $match->addBut((new But())->setClub($club)->setMinute(67)->setButeur($tom));
        $em->persist($match);
        $em->flush();
        $client->loginUser($admin);

        // Fiche du match → message proposé à toute l'équipe, avec score et buts, sans le rapport interne.
        $crawler = $client->request('GET', '/matchs/' . $match->getId());
        $crawler = $client->click($crawler->selectLink('Compte rendu aux familles')->link());
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Compte rendu du match');
        self::assertSelectorExists('input[name="form[cible]"][value="categorie"][checked]');
        self::assertSame((string) $u15->getId(), $crawler->filter('select[name="form[categorie]"] option[selected]')->attr('value'));
        $form = $crawler->selectButton('Envoyer')->form();
        self::assertSame("Compte rendu : U15 contre REIMS (2-1)", $form['form[sujet]']->getValue());
        $corps = $form['form[corps]']->getValue();
        self::assertStringContainsString('U15 contre REIMS · samedi 07/11 · Championnat · à domicile', $corps);
        self::assertStringContainsString("Score : CLUB TEST " . strtoupper($id) . " 2 – 1 REIMS (victoire).", $corps);
        self::assertStringContainsString('Mi-temps : 1 – 0.', $corps);
        self::assertStringContainsString("– 12e min : Léa MARTIN (passe de Tom DURAND)\n– 67e min : Tom DURAND", $corps);
        self::assertStringNotContainsString('40e', $corps);
        self::assertStringNotContainsString('Défense trop basse', $corps);

        $client->submit($form, ['form[corps]' => str_replace('Le mot de l\'éducateur : ', 'Le mot de l\'éducateur : belle réaction en 2e mi-temps.', $corps)]);
        self::assertResponseRedirects();
        $client->followRedirect();

        // La fiche du match indique l'envoi.
        $client->request('GET', '/matchs/' . $match->getId());
        self::assertSelectorTextContains('.affiche + p + p, .titre-page', 'Compte rendu envoyé aux familles');

        // La famille le lit dans son espace.
        $client->loginUser($parent);
        $crawler = $client->request('GET', '/espace/messages');
        self::assertResponseIsSuccessful();
        $crawler = $client->click($crawler->selectLink('Compte rendu : U15 contre REIMS (2-1)')->link());
        self::assertStringContainsString('belle réaction en 2e mi-temps', $crawler->text());
    }
}
