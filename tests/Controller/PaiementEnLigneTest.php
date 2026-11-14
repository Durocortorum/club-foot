<?php

namespace App\Tests\Controller;

use App\Entity\Categorie;
use App\Entity\Club;
use App\Entity\ConnexionHelloAsso;
use App\Entity\Cotisation;
use App\Entity\InscriptionStage;
use App\Entity\Joueur;
use App\Entity\PaiementEnLigne;
use App\Entity\Rencontre;
use App\Entity\Stage;
use App\Entity\TarifCotisation;
use App\Entity\Utilisateur;
use App\Tests\Simulation\HelloAssoSimule;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Paiement en ligne (HelloAsso simulé) : inscription d'un club avec l'option, connexion guidée du compte HelloAsso
 * (erreurs claires, secret chiffré), cotisation payée en 3 fois depuis l'espace famille, échéances enregistrées
 * une seule fois dans les Finances (retour du navigateur et notifications de HelloAsso).
 */
class PaiementEnLigneTest extends WebTestCase
{
    private ?int $clubId = null;

    protected function tearDown(): void
    {
        if ($this->clubId) {
            $connexion = static::getContainer()->get(EntityManagerInterface::class)->getConnection();
            foreach (['paiement_en_ligne', 'stage', 'paiement', 'cotisation', 'tarif_cotisation', 'connexion_hello_asso', 'utilisateur', 'joueur', 'categorie'] as $table) {
                $connexion->executeStatement("DELETE FROM $table WHERE club_id = ?", [$this->clubId]);
            }
            $connexion->executeStatement('DELETE FROM club WHERE id = ?', [$this->clubId]);
        }
        parent::tearDown();
    }

    public function testCotisationPayeeEnLigne(): void
    {
        HelloAssoSimule::remettreAZero();
        $client = static::createClient();
        $id = bin2hex(random_bytes(4));

        // Inscription du club avec l'option : dernière étape, la connexion HelloAsso.
        $crawler = $client->request('GET', '/inscription');
        $client->submit($crawler->selectButton('Créer mon club')->form([
            'inscription[club]' => "FC Test $id",
            'inscription[prenom]' => 'Paul',
            'inscription[nom]' => 'Président',
            'inscription[email]' => "president-$id@exemple.test",
            'inscription[motDePasse][first]' => 'motdepasse-solide',
            'inscription[motDePasse][second]' => 'motdepasse-solide',
            'inscription[paiementEnLigne]' => true,
            'inscription[conditions]' => true,
        ]));
        self::assertResponseRedirects('/club/paiement-en-ligne');
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $club = $em->getRepository(Club::class)->findOneBy(['nom' => "FC Test $id"]);
        $this->clubId = $club->getId();
        self::assertTrue($club->isPaiementEnLigne());
        $crawler = $client->followRedirect();
        self::assertSelectorTextContains('.etat-helloasso', 'Pas encore connecté');
        self::assertSelectorTextContains('.guide-helloasso', 'Intégrations et API');
        $client->request('GET', '/');
        self::assertSelectorTextContains('.flash-info', 'terminez la connexion du compte HelloAsso');

        // Connexion guidée : association introuvable, puis identifiants refusés, puis tout va bien.
        $crawler = $client->request('GET', '/club/paiement-en-ligne');
        $formulaire = fn ($crawler, array $valeurs) => $crawler->selectButton('Enregistrer et vérifier')->form($valeurs + ['form[actif]' => true, 'form[test]' => true, 'form[echeancesMax]' => 3]);
        $crawler = $client->submit($formulaire($crawler, ['form[slug]' => 'inconnue', 'form[clientId]' => 'client-test', 'form[secret]' => 'secret-tres-confidentiel']));
        self::assertSelectorTextContains('form', 'Association introuvable sur HelloAsso');
        HelloAssoSimule::$refuserIdentifiants = true;
        $crawler = $client->submit($formulaire($crawler, ['form[slug]' => 'https://www.helloasso-sandbox.com/associations/fc-test']));
        self::assertSelectorTextContains('form', 'Identifiants refusés par HelloAsso');
        HelloAssoSimule::$refuserIdentifiants = false;
        $crawler = $client->submit($formulaire($crawler, ['form[clientId]' => 'client-test']));
        self::assertResponseRedirects('/club/paiement-en-ligne');
        $crawler = $client->followRedirect();
        self::assertSelectorTextContains('.flash-succes', '✓ Paiement en ligne connecté au compte HelloAsso « Association FC-TEST »');
        self::assertSelectorTextContains('.etat-helloasso', 'Connecté à HelloAsso');
        $em->clear();
        $connexion = $em->getRepository(ConnexionHelloAsso::class)->findOneBy(['club' => $this->clubId]);
        self::assertSame('fc-test', $connexion->getSlug());
        self::assertStringNotContainsString('secret-tres-confidentiel', $connexion->getSecretChiffre(), 'secret chiffré en base');
        self::assertStringContainsString('/helloasso/notification/' . $connexion->getJetonNotification(), $crawler->filter('.adresse-notification')->text());

        // Une famille : cotisation U15 de 180 € pour Léa.
        $club = $em->find(Club::class, $this->clubId);
        $u15 = $em->getRepository(Categorie::class)->findOneBy(['club' => $club, 'nom' => 'U15']);
        $saison = Rencontre::saisonDe(new \DateTimeImmutable('today'));
        $em->persist((new TarifCotisation($u15, $saison))->setClub($club)->setMontant(180));
        $lea = (new Joueur())->setClub($club)->setCategorie($u15)->setNom('Martin')->setPrenom('Léa')->setDateNaissance(new \DateTimeImmutable('2012-05-01'));
        $em->persist($lea);
        $parent = (new Utilisateur())->setClub($club)->setFamille(true)->setEmail("parent-$id@exemple.test")->setNom('Martin')->setPrenom('Sophie')->setPassword('x')->addJoueur($lea);
        $em->persist($parent);
        $em->flush();

        $client->loginUser($parent);
        $crawler = $client->request('GET', '/espace');
        $bloc = $crawler->filter('.cotisation-famille');
        self::assertStringContainsString('Reste à payer : 180,00 €', $bloc->text());
        self::assertCount(3, $bloc->filter('select[name="echeances"] option'));
        $client->submit($bloc->selectButton('💳 Payer en ligne')->form(['echeances' => 3]));
        self::assertResponseRedirects();
        self::assertStringStartsWith('https://www.helloasso-sandbox.com/paiement-simule/', $client->getResponse()->headers->get('Location'));
        $intention = array_key_last(HelloAssoSimule::$intentions);
        $corps = HelloAssoSimule::$intentions[$intention]['corps'];
        self::assertSame(18000, $corps['totalAmount']);
        self::assertSame(6000, $corps['initialAmount']);
        self::assertSame([6000, 6000], array_column($corps['terms'], 'amount'));
        self::assertSame("parent-$id@exemple.test", $corps['payer']['email']);
        self::assertSame('Sophie', $corps['payer']['firstName']);
        $paiement = $em->getRepository(PaiementEnLigne::class)->findOneBy(['checkoutIntentId' => $intention]);
        self::assertSame($paiement->getId(), $corps['metadata']['paiement']);

        // Retour avant que HelloAsso ait validé : rien n'est enregistré.
        $client->request('GET', '/espace/paiement/' . $paiement->getId() . '/retour');
        $client->followRedirect();
        self::assertSelectorTextContains('.flash-info', 'en cours de traitement');

        // Paiement validé : première échéance (60 €) enregistrée dans les Finances.
        HelloAssoSimule::payer($intention, 1);
        $client->request('GET', '/espace/paiement/' . $paiement->getId() . '/retour');
        $crawler = $client->followRedirect();
        self::assertSelectorTextContains('.flash-succes', 'Paiement en 3 fois accepté');
        self::assertStringContainsString('Reste à payer : 120,00 €', $crawler->filter('.cotisation-famille')->text());
        $em->clear();
        $cotisation = $em->getRepository(Cotisation::class)->findOneBy(['joueur' => $lea->getId(), 'saison' => $saison]);
        self::assertSame(60.0, $cotisation->getPaye());
        self::assertSame(Cotisation::MODE_EN_LIGNE, $cotisation->getPaiements()->first()->getMode());

        // Échéance suivante : notification de HelloAsso (adresse avec jeton), enregistrée une seule fois.
        HelloAssoSimule::payer($intention, 2);
        $notification = json_encode(['eventType' => 'Payment', 'data' => ['order' => ['id' => $intention + 900000]]]);
        $client->request('POST', '/helloasso/notification/' . str_repeat('0', 40), [], [], ['CONTENT_TYPE' => 'application/json'], $notification);
        self::assertResponseStatusCodeSame(404);
        $client->request('POST', '/helloasso/notification/' . $connexion->getJetonNotification(), [], [], ['CONTENT_TYPE' => 'application/json'], $notification);
        self::assertResponseIsSuccessful();
        $client->request('POST', '/helloasso/notification/' . $connexion->getJetonNotification(), [], [], ['CONTENT_TYPE' => 'application/json'], $notification);
        $em->clear();
        $cotisation = $em->getRepository(Cotisation::class)->findOneBy(['joueur' => $lea->getId(), 'saison' => $saison]);
        self::assertSame(120.0, $cotisation->getPaye());
        self::assertCount(2, $cotisation->getPaiements());

        // Stage payant (45 €) où Léa est inscrite : réglé en ligne, inscription marquée réglée, recette du stage dans les Finances.
        $club = $em->find(Club::class, $this->clubId);
        $lea = $em->find(Joueur::class, $lea->getId());
        $stage = (new Stage())->setClub($club)->setTitre('Stage de Toussaint')->setDebut(new \DateTimeImmutable('+10 days'))->setTarif('45');
        $em->persist($stage);
        $inscription = $stage->inscrire($lea, true);
        $em->persist($inscription);
        $em->flush();
        $crawler = $client->request('GET', '/espace');
        $client->submit($crawler->selectButton('💳 Régler en ligne (45,00 €)')->form());
        self::assertResponseRedirects();
        $intentionStage = array_key_last(HelloAssoSimule::$intentions);
        self::assertSame(4500, HelloAssoSimule::$intentions[$intentionStage]['corps']['totalAmount']);
        self::assertArrayNotHasKey('terms', HelloAssoSimule::$intentions[$intentionStage]['corps']);
        $paiementStage = $em->getRepository(PaiementEnLigne::class)->findOneBy(['checkoutIntentId' => $intentionStage]);
        HelloAssoSimule::payer($intentionStage);
        $client->request('GET', '/espace/paiement/' . $paiementStage->getId() . '/retour');
        self::assertResponseRedirects('/espace#stages-' . $lea->getId());
        $crawler = $client->followRedirect();
        self::assertSelectorTextContains('.flash-succes', 'Merci');
        self::assertStringContainsString('réglé', $crawler->filter('.stage-famille')->text());
        self::assertCount(0, $crawler->filter('.payer-stage'));
        $em->clear();
        self::assertTrue($em->find(InscriptionStage::class, $inscription->getId())->isPaye());
        $client->loginUser($em->getRepository(Utilisateur::class)->findOneBy(['email' => "president-$id@exemple.test"]));
        $client->request('GET', '/finances');
        self::assertMatchesRegularExpression('/Stages \(inscriptions réglées\)\s*45,00 €/u', $client->getCrawler()->text());
        $client->loginUser($em->getRepository(Utilisateur::class)->findOneBy(['email' => "parent-$id@exemple.test"]));

        // Option retirée par le club : plus de bouton de paiement.
        $club = $em->find(Club::class, $this->clubId);
        $club->setPaiementEnLigne(false);
        $em->flush();
        $crawler = $client->request('GET', '/espace');
        self::assertCount(0, $crawler->filter('.cotisation-famille'));
    }
}
