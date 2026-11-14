<?php

namespace App\Tests\Controller;

use App\Entity\ArticleBoutique;
use App\Entity\Categorie;
use App\Entity\Club;
use App\Entity\CommandeBoutique;
use App\Entity\ConnexionHelloAsso;
use App\Entity\Cotisation;
use App\Entity\Joueur;
use App\Entity\PaiementEnLigne;
use App\Entity\Utilisateur;
use App\Service\Coffre;
use App\Service\CreationClub;
use App\Tests\Simulation\HelloAssoSimule;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Mime\Email;

/**
 * Boutique : le club crée ses articles (prix, tailles, stock, photo) ; la famille remplit son panier (stock respecté),
 * commande et paie en ligne (HelloAsso simulé) ou au club, annule ; le club prépare (famille prévenue) et remet ;
 * stock remis à l'annulation ; ventes dans les recettes des Finances.
 */
class BoutiqueTest extends WebTestCase
{
    private ?int $clubId = null;

    protected function tearDown(): void
    {
        if ($this->clubId) {
            $connexion = static::getContainer()->get(EntityManagerInterface::class)->getConnection();
            foreach (['paiement_en_ligne', 'commande_boutique', 'article_boutique', 'connexion_hello_asso', 'utilisateur', 'joueur', 'categorie'] as $table) {
                $connexion->executeStatement("DELETE FROM $table WHERE club_id = ?", [$this->clubId]);
            }
            $connexion->executeStatement('DELETE FROM club WHERE id = ?', [$this->clubId]);
        }
        parent::tearDown();
    }

    public function testBoutique(): void
    {
        HelloAssoSimule::remettreAZero();
        $client = static::createClient();
        $id = bin2hex(random_bytes(4));
        $admin = (new Utilisateur())->setEmail("admin-$id@exemple.test")->setNom('Test')->setPrenom('Admin');
        $club = (new Club())->setNom("Club Test $id")->setPaiementEnLigne(true);
        static::getContainer()->get(CreationClub::class)->creer($club, $admin, 'motdepasse-admin');
        $this->clubId = $club->getId();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->persist((new ConnexionHelloAsso())->setClub($club)->setSlug('fc-test')->setClientId('client')->setSecretChiffre(static::getContainer()->get(Coffre::class)->chiffrer('secret'))->setTest(true)->marquerVerifiee('FC TEST'));
        $u15 = $em->getRepository(Categorie::class)->findOneBy(['club' => $club, 'nom' => 'U15']);
        $lea = (new Joueur())->setClub($club)->setCategorie($u15)->setNom('Martin')->setPrenom('Léa');
        $em->persist($lea);
        $parent = (new Utilisateur())->setClub($club)->setFamille(true)->setEmail("parent-$id@exemple.test")->setNom('Martin')->setPrenom('Sophie')->setPassword('x')->addJoueur($lea);
        $em->persist($parent);
        $em->flush();

        // Le club crée un survêtement (S, M ; stock suivi, avec photo) et des chaussettes (taille unique, stock libre).
        $client->loginUser($admin);
        $crawler = $client->request('GET', '/boutique/articles/nouveau');
        $photo = tempnam(sys_get_temp_dir(), 'art') . '.png';
        imagepng(imagecreatetruecolor(40, 40), $photo);
        $form = $crawler->selectButton('Enregistrer')->form([
            'article_boutique[nom]' => 'Survêtement',
            'article_boutique[prix]' => '45',
            'article_boutique[taillesTexte]' => 'S, M',
            'article_boutique[enVente]' => true,
            'article_boutique[suiviStock]' => true,
        ]);
        $form['article_boutique[photo]']->upload($photo);
        $client->submit($form);
        $survet = $em->getRepository(ArticleBoutique::class)->findOneBy(['nom' => 'Survêtement']);
        self::assertResponseRedirects('/boutique/articles/' . $survet->getId() . '/modifier#stock');
        $crawler = $client->followRedirect();
        $client->submit($crawler->selectButton('Enregistrer')->form(['stock[S]' => 2, 'stock[M]' => 0]));
        $crawler = $client->request('GET', '/boutique/articles/nouveau');
        $client->submit($crawler->selectButton('Enregistrer')->form([
            'article_boutique[nom]' => 'Chaussettes', 'article_boutique[prix]' => '8', 'article_boutique[enVente]' => true,
        ]));
        $crawler = $client->request('GET', '/boutique/articles');
        self::assertStringContainsString('S : 2 · M : 0', $crawler->filter('table.liste')->text());
        $em->clear();
        $survet = $em->getRepository(ArticleBoutique::class)->findOneBy(['nom' => 'Survêtement']);
        $chaussettes = $em->getRepository(ArticleBoutique::class)->findOneBy(['nom' => 'Chaussettes']);

        // La famille : menu Boutique, taille M épuisée, stock respecté, panier de 114 €.
        $client->loginUser($parent);
        $crawler = $client->request('GET', '/espace/boutique');
        self::assertSelectorExists('nav a[href="/espace/boutique"]');
        self::assertCount(2, $crawler->filter('.article-boutique'));
        self::assertNotNull($crawler->filter('.article-boutique select[name="taille"] option[value="M"]')->attr('disabled'), 'taille M épuisée');
        $client->request('GET', '/espace/boutique/photo/' . str_replace('.jpg', '', $survet->getPhoto()));
        self::assertResponseIsSuccessful();
        $ajouter = function (ArticleBoutique $a, string $taille, int $quantite) use ($client) {
            $crawler = $client->request('GET', '/espace/boutique');
            $form = $crawler->filter('form[action="/espace/boutique/ajouter/' . $a->getId() . '"]')->form();
            $valeurs = ['quantite' => $quantite];
            if (ArticleBoutique::TAILLE_UNIQUE !== $taille) {
                $valeurs['taille'] = $taille;
            }
            $client->submit($form, $valeurs);

            return $client->followRedirect();
        };
        $crawler = $ajouter($survet, 'S', 3);
        self::assertSelectorTextContains('.flash-erreur', 'Il n\'en reste que 2 en S.');
        $ajouter($survet, 'S', 2);
        $crawler = $ajouter($chaussettes, ArticleBoutique::TAILLE_UNIQUE, 3);
        self::assertStringContainsString('114,00 €', $crawler->filter('#panier tfoot')->text());

        // Commande payée en ligne : page HelloAsso, puis retour, commande réglée ; le stock S est épuisé.
        $client->submit($crawler->selectButton('💳 Commander et payer en ligne')->form());
        self::assertResponseRedirects();
        self::assertStringStartsWith('https://www.helloasso-sandbox.com/paiement-simule/', $client->getResponse()->headers->get('Location'));
        $intention = array_key_last(HelloAssoSimule::$intentions);
        self::assertSame(11400, HelloAssoSimule::$intentions[$intention]['corps']['totalAmount']);
        HelloAssoSimule::payer($intention);
        $paiement = $em->getRepository(PaiementEnLigne::class)->findOneBy(['checkoutIntentId' => $intention]);
        $client->request('GET', '/espace/paiement/' . $paiement->getId() . '/retour');
        self::assertResponseRedirects('/espace/boutique#commandes');
        $crawler = $client->followRedirect();
        self::assertStringContainsString('Payée, à préparer', $crawler->filter('#commandes')->text());
        self::assertCount(0, $crawler->filter('#panier tfoot'));
        $em->clear();
        $commande = $em->getRepository(CommandeBoutique::class)->findOneBy(['club' => $this->clubId], ['id' => 'ASC']);
        self::assertSame(Cotisation::MODE_EN_LIGNE, $commande->getModeReglement());
        self::assertSame(0, $em->find(ArticleBoutique::class, $survet->getId())->disponible('S'));

        // Seconde commande à régler au club, annulée par la famille.
        $crawler = $ajouter($chaussettes, ArticleBoutique::TAILLE_UNIQUE, 1);
        $client->submit($crawler->selectButton('Commander et régler au club')->form());
        $crawler = $client->followRedirect();
        self::assertSelectorTextContains('.flash-succes', 'à régler au club');
        $client->submit($crawler->filter('form[action$="/annuler"]')->form());
        $crawler = $client->followRedirect();
        self::assertStringContainsString('Annulée', $crawler->filter('#commandes')->text());

        // Le club : commande prête (famille prévenue par e-mail), puis remise ; ventes dans les Finances.
        $client->loginUser($em->getRepository(Utilisateur::class)->findOneBy(['email' => "admin-$id@exemple.test"]));
        $crawler = $client->request('GET', '/boutique');
        self::assertStringContainsString('N° ' . $commande->getNumero(), $crawler->filter('#commande-' . $commande->getId())->text());
        $client->submit($crawler->filter('#commande-' . $commande->getId())->selectButton('Prête à retirer')->form());
        $mails = array_filter(self::getMailerMessages(), fn ($m) => $m instanceof Email && str_contains((string) $m->getSubject(), 'votre commande est prête'));
        self::assertCount(1, $mails);
        $crawler = $client->followRedirect();
        $client->submit($crawler->filter('#commande-' . $commande->getId())->selectButton('Remise à la famille')->form());
        $client->followRedirect();
        $em->clear();
        self::assertSame(CommandeBoutique::REMISE, $em->find(CommandeBoutique::class, $commande->getId())->getStatut());
        $client->request('GET', '/finances');
        self::assertMatchesRegularExpression('/Boutique \(commandes réglées\)\s*114,00 €/u', $client->getCrawler()->text());

        // Annulation par le club d'une commande réglée : stock remis.
        $client->loginUser($em->getRepository(Utilisateur::class)->findOneBy(['email' => "parent-$id@exemple.test"]));
        HelloAssoSimule::remettreAZero();
        $crawler = $ajouter($chaussettes, ArticleBoutique::TAILLE_UNIQUE, 2);
        $client->submit($crawler->selectButton('Commander et régler au club')->form());
        $em->clear();
        $troisieme = $em->getRepository(CommandeBoutique::class)->findOneBy(['club' => $this->clubId], ['id' => 'DESC']);
        $client->loginUser($em->getRepository(Utilisateur::class)->findOneBy(['email' => "admin-$id@exemple.test"]));
        $crawler = $client->request('GET', '/boutique?filtre=a_regler');
        $bloc = $crawler->filter('#commande-' . $troisieme->getId());
        $client->submit($bloc->selectButton('Réglée au club')->form(['mode' => 'Espèces']));
        $client->followRedirect();
        $em->clear();
        self::assertSame('Espèces', $em->getRepository(CommandeBoutique::class)->find($troisieme->getId())->getModeReglement());
        @unlink($photo);
    }
}
