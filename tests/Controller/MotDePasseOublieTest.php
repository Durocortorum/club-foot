<?php

namespace App\Tests\Controller;

use App\Entity\Club;
use App\Entity\Utilisateur;
use App\Service\CreationClub;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Mot de passe oublié : lien reçu par e-mail, nouveau mot de passe, lien à usage unique.
 * Une adresse inconnue obtient la même réponse, sans e-mail. Club fictif supprimé à la fin.
 */
class MotDePasseOublieTest extends WebTestCase
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
            foreach (['rencontre', 'joueur', 'club'] as $table) {
                $connexion->executeStatement("DELETE FROM $table WHERE " . ('club' === $table ? 'id' : 'club_id') . ' = ?', [$this->clubId]);
            }
        }
        parent::tearDown();
    }

    public function testNouveauMotDePasse(): void
    {
        $id = bin2hex(random_bytes(4));
        $email = "admin-$id@exemple.test";
        $admin = (new Utilisateur())->setEmail($email)->setNom('Test')->setPrenom('Admin');
        $club = (new Club())->setNom("Club Test $id");
        static::getContainer()->get(CreationClub::class)->creer($club, $admin, 'ancien-mot-de-passe');
        $this->clubId = $club->getId();

        // Le lien est sur la page de connexion.
        $crawler = $this->client->request('GET', '/connexion');
        $this->client->click($crawler->selectLink('Mot de passe oublié ?')->link());
        self::assertResponseIsSuccessful();

        // Adresse inconnue : même réponse, aucun e-mail.
        $this->client->submitForm('Recevoir le lien', ['email' => "inconnu-$id@exemple.test"]);
        self::assertSelectorTextContains('h1', 'Vérifiez vos e-mails');
        self::assertEmailCount(0);

        // Adresse du compte (casse et espaces ignorés) : un e-mail avec le lien.
        $this->client->request('GET', '/mot-de-passe-oublie');
        $this->client->submitForm('Recevoir le lien', ['email' => '  ' . strtoupper($email) . ' ']);
        self::assertEmailCount(1);
        $message = self::getMailerMessage();
        self::assertInstanceOf(\Symfony\Component\Mime\Email::class, $message);
        self::assertEmailAddressContains($message, 'to', $email);
        self::assertSame(1, preg_match('#https?://\S+/mot-de-passe/[0-9a-f]{48}#', $message->getTextBody(), $m));
        $lien = $m[0];

        // Mots de passe différents : refusé ; puis accepté et connecté.
        $crawler = $this->client->request('GET', $lien);
        self::assertResponseIsSuccessful();
        $form = $crawler->selectButton('Enregistrer et me connecter')->form();
        $this->client->submit($form, ['form[motDePasse][first]' => 'nouveau-secret', 'form[motDePasse][second]' => 'autre']);
        self::assertResponseStatusCodeSame(422);
        $this->client->submit($form, ['form[motDePasse][first]' => 'nouveau-secret', 'form[motDePasse][second]' => 'nouveau-secret']);
        self::assertResponseRedirects('/');

        // Le lien ne sert qu'une fois, et le nouveau mot de passe fonctionne.
        $this->client->restart();
        $this->client->request('GET', $lien);
        self::assertResponseStatusCodeSame(410);
        $crawler = $this->client->request('GET', '/connexion');
        $this->client->submit($crawler->selectButton('Se connecter')->form(), ['email' => $email, 'mot_de_passe' => 'nouveau-secret']);
        self::assertResponseRedirects('/');
        $this->client->followRedirect();
        self::assertResponseIsSuccessful();

        // Déjà connecté : la demande pour un autre compte fonctionne quand même.
        $this->client->request('GET', '/mot-de-passe-oublie');
        self::assertResponseIsSuccessful();
        $this->client->submitForm('Recevoir le lien', ['email' => $email]);
        self::assertSelectorTextContains('h1', 'Vérifiez vos e-mails');
        self::assertEmailCount(1);
    }
}
