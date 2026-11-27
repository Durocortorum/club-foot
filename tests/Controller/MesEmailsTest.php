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
 * « Mes e-mails » (Mon compte) : la famille choisit les e-mails qu'elle reçoit ; l'encadrement, seulement les messages.
 * Données fictives dans un club créé pour le test, supprimé à la fin.
 */
class MesEmailsTest extends WebTestCase
{
    private ?int $clubId = null;

    protected function tearDown(): void
    {
        if ($this->clubId) {
            $connexion = static::getContainer()->get(EntityManagerInterface::class)->getConnection();
            foreach (['joueur', 'club'] as $table) {
                $connexion->executeStatement("DELETE FROM $table WHERE " . ('club' === $table ? 'id' : 'club_id') . ' = ?', [$this->clubId]);
            }
        }
        parent::tearDown();
    }

    public function testChoixDesEmails(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $id = bin2hex(random_bytes(4));
        $admin = (new Utilisateur())->setEmail("admin-$id@exemple.test")->setNom('Test')->setPrenom('Admin');
        $club = (new Club())->setNom("Club Test $id");
        static::getContainer()->get(CreationClub::class)->creer($club, $admin, 'motdepasse-admin');
        $this->clubId = $club->getId();
        $categorie = $em->getRepository(Categorie::class)->findOneBy(['club' => $club], ['ordre' => 'ASC']);
        $joueur = (new Joueur())->setClub($club)->setCategorie($categorie)->setNom('Martin')->setPrenom('Léa');
        $famille = (new Utilisateur())->setFamille(true)->setEmail("famille-$id@exemple.test")->setNom('Martin')->setPrenom('Léa')
            ->setPassword('x')->addJoueur($joueur);
        $famille->setClub($club);
        $em->persist($joueur);
        $em->persist($famille);
        $em->flush();
        self::assertTrue($famille->recoitEmail('convocations'), 'tout est reçu par défaut');

        // Famille : les quatre choix, tous cochés ; elle coupe les convocations et la boutique.
        $client->loginUser($famille);
        $crawler = $client->request('GET', '/espace');
        self::assertSelectorTextContains('.espace-carte[href$="#mes-emails"]', 'Tous les e-mails');
        $crawler = $client->request('GET', '/mon-compte');
        self::assertCount(4, $crawler->filter('#mes-emails input[name="emails[]"]:checked'));
        $form = $crawler->selectButton('Enregistrer mes choix')->form();
        $form['emails'][0]->untick();
        $form['emails'][3]->untick();
        $client->submit($form);
        self::assertResponseRedirects('/mon-compte#mes-emails');

        $em->clear();
        $compte = $em->getRepository(Utilisateur::class)->findOneBy(['email' => "famille-$id@exemple.test"]);
        self::assertSame(['convocations', 'boutique'], $compte->getEmailsCoupes());
        self::assertFalse($compte->recoitEmail('convocations'));
        self::assertTrue($compte->recoitEmail('messages'));
        $crawler = $client->request('GET', '/espace');
        self::assertSelectorTextContains('.espace-carte[href$="#mes-emails"]', '2 types d\'e-mail coupés');

        // Encadrement : seulement le choix des messages.
        $client->loginUser($em->getRepository(Utilisateur::class)->findOneBy(['email' => "admin-$id@exemple.test"]));
        $crawler = $client->request('GET', '/mon-compte');
        self::assertCount(1, $crawler->filter('#mes-emails input[name="emails[]"]'));
        self::assertSame('messages', $crawler->filter('#mes-emails input[name="emails[]"]')->attr('value'));
    }
}
