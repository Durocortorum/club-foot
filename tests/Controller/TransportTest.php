<?php

namespace App\Tests\Controller;

use App\Entity\Categorie;
use App\Entity\Club;
use App\Entity\Deplacement;
use App\Entity\Utilisateur;
use App\Service\CreationClub;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Transport : déplacements en voiture personnelle (frais km), remboursement et don au club avec récapitulatif,
 * minibus avec échéance signalée, frais remboursés comptés dans la synthèse des finances.
 */
class TransportTest extends WebTestCase
{
    private ?int $clubId = null;

    protected function tearDown(): void
    {
        if ($this->clubId) {
            $connexion = static::getContainer()->get(EntityManagerInterface::class)->getConnection();
            foreach (['deplacement', 'vehicule', 'conducteur', 'utilisateur', 'categorie'] as $table) {
                $connexion->executeStatement("DELETE FROM $table WHERE club_id = ?", [$this->clubId]);
            }
            $connexion->executeStatement('DELETE FROM club WHERE id = ?', [$this->clubId]);
        }
        parent::tearDown();
    }

    public function testTransportEtFrais(): void
    {
        $client = static::createClient();
        $id = bin2hex(random_bytes(4));
        $admin = (new Utilisateur())->setEmail("admin-$id@exemple.test")->setNom('Test')->setPrenom('Admin');
        $club = (new Club())->setNom("Club Test $id");
        static::getContainer()->get(CreationClub::class)->creer($club, $admin, 'motdepasse-admin');
        $this->clubId = $club->getId();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $u15 = $em->getRepository(Categorie::class)->findOneBy(['club' => $club, 'nom' => 'U15']);
        $client->loginUser($admin);

        // Minibus dont le contrôle technique est dans 10 jours : signalé.
        $crawler = $client->request('GET', '/transport/vehicules/nouveau');
        $client->submit($crawler->selectButton('Enregistrer')->form(['vehicule[nom]' => 'Minibus Trafic', 'vehicule[places]' => '9', 'vehicule[controleTechnique]' => (new \DateTimeImmutable('+10 days'))->format('Y-m-d')]));
        $client->followRedirect();

        // Voiture personnelle sans kilomètres : refusé.
        $crawler = $client->request('GET', '/transport/nouveau');
        $client->submit($crawler->selectButton('Enregistrer')->form(['deplacement[objet]' => 'Match U15 à Sedan', 'deplacement[moyen]' => Deplacement::VOITURE, 'deplacement[conducteur]' => 'Paul Martin']));
        self::assertResponseStatusCodeSame(422);

        // Deux trajets de Paul (200 km, puis 100 km + 6 € de péage) au barème de 0,335 €.
        foreach ([['Match U15 à Sedan', '200', ''], ['Tournoi de Pâques', '100', '6']] as [$objet, $km, $frais]) {
            $crawler = $client->request('GET', '/transport/nouveau');
            $client->submit($crawler->selectButton('Enregistrer')->form([
                'deplacement[objet]' => $objet, 'deplacement[categorie]' => $u15->getId(), 'deplacement[moyen]' => Deplacement::VOITURE,
                'deplacement[conducteur]' => 'Paul  Martin', 'deplacement[km]' => $km, 'deplacement[frais]' => $frais,
            ]));
            $crawler = $client->followRedirect();
        }
        self::assertStringContainsString('39,50 € de frais pour Paul Martin', $crawler->filter('.flash-succes')->text(), '100 km × 0,335 + 6 € ; espaces du nom nettoyés');
        self::assertStringContainsString('Minibus Trafic : contrôle technique à faire avant le', $crawler->filter('.flash-info')->text());

        // Frais : 67 € + 39,50 € à rembourser ; le trésorier rembourse le premier, Paul donne le second.
        $crawler = $client->request('GET', '/transport/frais');
        self::assertStringContainsString('106,50 € à rembourser', $crawler->filter('section.carte')->text());
        $client->submit($crawler->filter('form button[title="Marquer remboursé"]')->first()->form());
        $crawler = $client->followRedirect();
        $client->submit($crawler->filter('form button[title^="Le conducteur renonce"]')->last()->form());
        $crawler = $client->followRedirect();
        $tuiles = $crawler->filter('.finances-tuiles .tuile-valeur')->each(fn ($t) => $t->text());
        self::assertSame(['0,00 €', '67,00 €', '39,50 €'], $tuiles);

        // Récapitulatif pour le reçu fiscal : le trajet donné seulement.
        $crawler = $client->click($crawler->selectLink('Récapitulatif pour le reçu fiscal')->link());
        self::assertStringContainsString('Paul Martin', $crawler->filter('.recapitulatif-frais')->text());
        self::assertCount(1, $crawler->filter('.recapitulatif-frais tbody tr'));
        self::assertStringContainsString('39,50 €', $crawler->filter('.recapitulatif-frais tfoot')->text());

        // Conducteurs : Paul n'est pas dans la liste → signalé au déplacement suivant.
        $crawler = $client->request('GET', '/transport/nouveau');
        $client->submit($crawler->selectButton('Enregistrer')->form(['deplacement[objet]' => 'Match U15 à Reims', 'deplacement[moyen]' => Deplacement::VOITURE, 'deplacement[conducteur]' => 'Paul Martin', 'deplacement[km]' => '20']));
        $crawler = $client->followRedirect();
        self::assertStringContainsString('Paul Martin n\'est pas dans la liste des conducteurs', $crawler->filter('.flash-erreur')->text());

        // Ajouté, permis contrôlé il y a 13 mois : à contrôler, rappelé sur l'accueil.
        $crawler = $client->request('GET', '/transport/conducteurs/nouveau');
        $client->submit($crawler->selectButton('Enregistrer')->form(['conducteur[nom]' => 'paul martin', 'conducteur[controleLe]' => (new \DateTimeImmutable('-13 months'))->format('Y-m-d')]));
        $crawler = $client->followRedirect();
        self::assertStringContainsString('À contrôler', $crawler->filter('tbody tr')->first()->text());
        $crawler = $client->request('GET', '/');
        self::assertStringContainsString('Permis à contrôler : paul martin', $crawler->filter('.rappel-transport')->text());

        // « Permis contrôlé aujourd'hui » : en règle, plus de rappel ; le prochain contrôle dans un an.
        $crawler = $client->request('GET', '/transport/conducteurs');
        $client->submit($crawler->selectButton('Permis contrôlé aujourd\'hui')->form());
        $crawler = $client->followRedirect();
        self::assertStringContainsString('prochain contrôle le ' . (new \DateTimeImmutable('+12 months'))->format('d/m/Y'), $crawler->filter('.flash-succes')->text());
        self::assertStringContainsString('En règle', $crawler->filter('tbody tr')->first()->text());
        $crawler = $client->request('GET', '/');
        self::assertStringNotContainsString('Permis à contrôler', $crawler->filter('.rappel-transport')->count() ? $crawler->filter('.rappel-transport')->text() : '');

        // Synthèse des finances : les 67 € remboursés sont une dépense.
        $crawler = $client->request('GET', '/finances');
        self::assertStringContainsString('Frais kilométriques (remboursés)', $crawler->filter('table.rubriques')->last()->text());
        self::assertStringContainsString('67,00 €', $crawler->filter('table.rubriques')->last()->text());
    }
}
