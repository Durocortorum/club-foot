<?php

namespace App\Tests\Controller;

use App\Entity\Categorie;
use App\Entity\Club;
use App\Entity\Joueur;
use App\Entity\Utilisateur;
use App\Service\CreationClub;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Finances : tarifs par catégorie, paiements et réduction, entrées d'un match, dépense, contrat à échéance,
 * synthèse de la saison ; page fermée sans le droit « Finances ».
 */
class FinancesTest extends WebTestCase
{
    private ?int $clubId = null;

    protected function tearDown(): void
    {
        if ($this->clubId) {
            $connexion = static::getContainer()->get(EntityManagerInterface::class)->getConnection();
            foreach (['paiement', 'cotisation', 'tarif_cotisation', 'operation', 'contrat', 'utilisateur', 'joueur', 'categorie'] as $table) {
                $connexion->executeStatement("DELETE FROM $table WHERE club_id = ?", [$this->clubId]);
            }
            $connexion->executeStatement('DELETE FROM club WHERE id = ?', [$this->clubId]);
        }
        parent::tearDown();
    }

    public function testFinancesDeLaSaison(): void
    {
        $client = static::createClient();
        $id = bin2hex(random_bytes(4));
        $admin = (new Utilisateur())->setEmail("admin-$id@exemple.test")->setNom('Test')->setPrenom('Admin');
        $club = (new Club())->setNom("Club Test $id");
        static::getContainer()->get(CreationClub::class)->creer($club, $admin, 'motdepasse-admin');
        $this->clubId = $club->getId();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $u13 = $em->getRepository(Categorie::class)->findOneBy(['club' => $club, 'nom' => 'U13']);
        $tom = (new Joueur())->setClub($club)->setCategorie($u13)->setNom('Petit')->setPrenom('Tom');
        $lea = (new Joueur())->setClub($club)->setCategorie($u13)->setNom('Martin')->setPrenom('Léa');
        $coach = (new Utilisateur())->setClub($club)->setEmail("coach-$id@exemple.test")->setNom('Sans')->setPrenom('Droit')->setPassword('x');
        $em->persist($tom);
        $em->persist($lea);
        $em->persist($coach);
        $em->flush();

        // Sans le droit « Finances » : ni menu, ni page.
        $client->loginUser($coach);
        $crawler = $client->request('GET', '/');
        self::assertCount(0, $crawler->filter('nav.menu a:contains("Finances")'));
        $client->request('GET', '/finances');
        self::assertResponseStatusCodeSame(403);

        // L'administrateur a le droit : tarif U13 à 150 €.
        $client->loginUser($admin);
        $crawler = $client->request('GET', '/finances/cotisations');
        self::assertResponseIsSuccessful();
        $client->submit($crawler->selectButton('Enregistrer les tarifs')->form(["tarifs[{$u13->getId()}]" => '150']));
        $crawler = $client->followRedirect();

        // Tom paie 100 € par chèque ; Léa a une réduction à 120 € et paie tout.
        $client->submit($crawler->filter("#joueur-{$tom->getId()} form[action$=\"/paiement\"]")->form(['montant' => '100', 'mode' => 'Chèque', 'note' => 'n° 1234']));
        $crawler = $client->followRedirect();
        $client->submit($crawler->filter("#joueur-{$lea->getId()} form[action$=\"/montant\"]")->form(['montant' => '120', 'remarque' => 'Fratrie']));
        $crawler = $client->followRedirect();
        $form = $crawler->filter("#joueur-{$lea->getId()} form[action$=\"/paiement\"]")->form();
        self::assertSame('120,00', $form['montant']->getValue(), 'le paiement propose le reste dû');
        $client->submit($form, ['mode' => 'Virement']);
        $crawler = $client->followRedirect();
        self::assertStringContainsString('Partiel', $crawler->filter("#joueur-{$tom->getId()}")->text());
        self::assertStringContainsString('50,00 €', $crawler->filter("#joueur-{$tom->getId()}")->text());
        self::assertStringContainsString('Payé', $crawler->filter("#joueur-{$lea->getId()}")->text());

        // Entrées d'un match : 85 entrées à 3 € ; achat de matériel : 120 €.
        $crawler = $client->request('GET', '/finances/operations/nouvelle?sens=recette&modele=entrees');
        $client->submit($crawler->selectButton('Enregistrer')->form(['operation[libelle]' => 'Match U19 contre Troyes', 'operation[nbEntrees]' => '85', 'operation[prixPlace]' => '3']));
        $crawler = $client->followRedirect();
        self::assertStringContainsString('255,00', $crawler->filter('.flash-succes')->text());
        $crawler = $client->request('GET', '/finances/operations/nouvelle?sens=depense');
        $client->submit($crawler->selectButton('Enregistrer')->form(['operation[rubrique]' => 'Matériel', 'operation[libelle]' => '20 ballons', 'operation[montant]' => '120']));
        $client->followRedirect();

        // Contrat qui se termine dans un mois : signalé sur la synthèse.
        $crawler = $client->request('GET', '/finances/contrats/nouveau');
        $client->submit($crawler->selectButton('Enregistrer')->form([
            'contrat[objet]' => 'Location du synthétique', 'contrat[type]' => 'Location / installations', 'contrat[partie]' => 'Mairie',
            'contrat[fin]' => (new \DateTimeImmutable('+30 days'))->format('Y-m-d'), 'contrat[montant]' => '500',
        ]));
        $client->followRedirect();

        // Synthèse : 220 € de cotisations encaissées, 50 € à recevoir, solde 220 + 255 − 120 = 355 €.
        $crawler = $client->request('GET', '/finances');
        self::assertResponseIsSuccessful();
        $tuiles = $crawler->filter('.finances-tuiles .tuile')->each(fn ($t) => preg_replace('/\s+/u', ' ', $t->text()));
        self::assertStringContainsString('220,00 € cotisations encaissées sur 270,00 €', $tuiles[0]);
        self::assertStringContainsString('50,00 €', $tuiles[1]);
        self::assertStringContainsString('355,00 €', $tuiles[4]);
        self::assertStringContainsString('Location du synthétique', $crawler->filter('section:contains("Contrats à échéance")')->text());
        self::assertStringContainsString('Entrées des matchs', $crawler->filter('table.rubriques')->first()->text());
    }
}
