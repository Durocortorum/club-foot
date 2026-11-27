<?php

namespace App\Tests\Controller;

use App\Entity\Categorie;
use App\Entity\Club;
use App\Entity\Joueur;
use App\Entity\Rencontre;
use App\Entity\Utilisateur;
use App\Service\CreationClub;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Comptes famille : invitation par l'encadrement, activation par le lien, espace famille
 * et cloisonnement (un compte famille n'ouvre aucune page de l'encadrement).
 * Données fictives dans un club créé pour le test, supprimé à la fin (base « _test »).
 */
class AccesFamilleTest extends WebTestCase
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
            // Le reste des données du club part avec lui (clés étrangères ON DELETE CASCADE),
            // mais matchs et joueurs référencent aussi les catégories : on les supprime d'abord.
            $connexion = $this->em()->getConnection();
            foreach (['rencontre', 'joueur', 'club'] as $table) {
                $connexion->executeStatement("DELETE FROM $table WHERE " . ('club' === $table ? 'id' : 'club_id') . ' = ?', [$this->clubId]);
            }
        }
        parent::tearDown();
    }

    public function testParcoursComplet(): void
    {
        $id = bin2hex(random_bytes(4));
        $admin = (new Utilisateur())->setEmail("admin-$id@exemple.test")->setNom('Test')->setPrenom('Admin');
        $club = (new Club())->setNom("Club Test $id");
        static::getContainer()->get(CreationClub::class)->creer($club, $admin, 'motdepasse-admin');
        $this->clubId = $club->getId();

        $em = $this->em();
        $categorie = $em->getRepository(Categorie::class)->findOneBy(['club' => $club], ['ordre' => 'ASC']);
        $parent = "parent-$id@exemple.test";
        $lea = $this->joueur($club, $categorie, 'Léa', $parent, "autre-$id@exemple.test");
        $tom = $this->joueur($club, $categorie, 'Tom', null, $parent);
        $em->persist((new Rencontre())->setClub($club)->setCategorie($categorie)->setDate(new \DateTimeImmutable('+3 days'))
            ->setHeure('14h30')->setDomicile("CLUB TEST $id")->setExterieur('Adversaire FC'));
        $em->flush();

        // Fiche : un portable et un e-mail par parent, plus de téléphones maison.
        $this->client->loginUser($admin);
        $this->client->request('GET', '/joueurs/' . $lea->getId() . '/modifier');
        self::assertResponseIsSuccessful();
        foreach (['telPortable1', 'emailParent1', 'telPortable2', 'emailParent2'] as $champ) {
            self::assertSelectorExists("[name=\"joueur[$champ]\"]");
        }
        self::assertSelectorNotExists('[name="joueur[telMaisonMere]"]');
        self::assertInputValueSame('joueur[emailParent2]', "autre-$id@exemple.test");

        // --- L'encadrement invite le parent depuis la fiche de Léa : le lien s'affiche une fois.
        $crawler = $this->client->request('GET', '/joueurs/' . $lea->getId());
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('#famille', $parent);
        self::assertSelectorTextContains('#famille', "autre-$id@exemple.test");
        $this->client->submit($crawler->filter("#famille form input[value=\"$parent\"]")->closest('form')->form());
        $this->client->followRedirect();
        $premierLien = $this->lienAffiche();

        // Tom (même parent) : le compte est réutilisé, un nouveau lien remplace le premier.
        $crawler = $this->client->request('GET', '/joueurs/' . $tom->getId());
        $this->client->submit($crawler->filter("#famille form input[value=\"$parent\"]")->closest('form')->form());
        $this->client->followRedirect();
        $lien = $this->lienAffiche();
        self::assertNotSame($premierLien, $lien);

        $compte = $this->compte($parent);
        self::assertTrue($compte->isFamille());
        self::assertCount(2, $compte->getJoueurs());
        self::assertNull($compte->getPassword());

        // Lien de l'espace famille ouvert avec un compte de l'encadrement : message explicite.
        $this->client->request('GET', '/espace');
        $this->client->followRedirect();
        self::assertSelectorTextContains('.flash-info', 'espace famille');

        // La liste des utilisateurs ne montre que l'encadrement.
        $this->client->request('GET', '/utilisateurs');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextNotContains('table', $parent);

        // Une adresse de l'encadrement ne peut pas devenir un compte famille.
        $this->client->request('POST', '/joueurs/' . $lea->getId() . '/famille/inviter', [
            'email' => $admin->getEmail(),
            '_token' => $this->jeton('/joueurs/' . $lea->getId(), '#famille form'),
        ]);
        $this->client->followRedirect();
        self::assertSelectorTextContains('.flash-erreur', 'encadrement');

        // Page « Accès familles » : il reste l'autre adresse de Léa, invitée d'un coup pour toute la catégorie.
        $crawler = $this->client->request('GET', '/familles?categorie=' . $categorie->getId());
        self::assertResponseIsSuccessful();
        $this->client->submit($crawler->selectButton('Inviter l\'adresse sans compte')->form());
        $this->client->followRedirect();
        self::assertSelectorTextContains('.liens-famille', "autre-$id@exemple.test");
        self::assertSelectorNotExists('form[action="/familles/inviter"]');

        // --- Le parent ouvre le lien, sans être connecté.
        $this->client->restart();
        $this->client->request('GET', $premierLien);
        self::assertResponseStatusCodeSame(410);

        $crawler = $this->client->request('GET', $lien);
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.connexion', 'Léa');
        $form = $crawler->selectButton('Activer mon compte')->form();
        $this->client->submit($form, ['form[motDePasse][first]' => 'secret-famille', 'form[motDePasse][second]' => 'autre-chose', 'form[accord]' => true]);
        self::assertResponseStatusCodeSame(422);
        $this->client->submit($form, ['form[motDePasse][first]' => 'secret-famille', 'form[motDePasse][second]' => 'secret-famille', 'form[accord]' => true]);
        self::assertResponseRedirects('/espace');
        // Conditions d'utilisation acceptées à l'activation : version enregistrée sur le compte.
        self::assertSame(static::getContainer()->getParameter('legal')['version'],
            static::getContainer()->get('doctrine')->getConnection()->fetchOne('SELECT conditions_version FROM utilisateur WHERE email = ?', [$this->client->getContainer()->get('security.token_storage')->getToken()?->getUserIdentifier()]));
        $this->client->followRedirect();
        self::assertSelectorTextContains('main', 'Léa');
        self::assertSelectorTextContains('main', 'Tom');
        self::assertSelectorTextContains('main', 'ADVERSAIRE FC');
        self::assertSelectorNotExists('a[href="/joueurs"]');
        // Accueil « Mon espace » : une carte par enfant et ses rubriques (convocations, classement, calendrier, équipe).
        $crawler = $this->client->getCrawler();
        self::assertCount(2, $crawler->filter('.espace-joueur'));
        $premier = $crawler->filter('.espace-menu')->first();
        self::assertCount(1, $premier->filter('.espace-carte:contains("Convocations")'));
        self::assertCount(1, $premier->filter('.espace-carte.doublon-barre-app:contains("Convocations")'), 'cachée sur téléphone : Convocations est dans la barre du bas');
        self::assertCount(0, $premier->filter('.espace-carte:contains("Calendrier")'), 'pas de doublon avec la barre du bas');
        self::assertCount(1, $crawler->filter('.espace-menu')->eq(1)->filter('.espace-carte:contains("Statistiques")'), 'carte Statistiques pour chaque enfant');
        self::assertSelectorTextContains('.barre-app', 'Convoc.');
        // Pages « Classement » et « Mon équipe » de ses enfants seulement.
        $this->client->request('GET', '/espace/equipe/' . $lea->getId());
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('main', 'Léa');
        $this->client->request('GET', '/espace/classement/' . $lea->getId());
        self::assertResponseIsSuccessful();
        $autre = $this->joueur($this->em()->find(Club::class, $club->getId()), $this->em()->find(Categorie::class, $categorie->getId()), 'Autre', null, null);
        $this->em()->flush();
        $this->client->request('GET', '/espace/equipe/' . $autre->getId());
        self::assertResponseStatusCodeSame(404, 'pas l\'équipe d\'un joueur qui n\'est pas le sien');

        // --- Cloisonnement : aucune page de l'encadrement.
        foreach (['/joueurs', '/joueurs/' . $lea->getId(), '/matchs', '/statistiques', '/presences', '/utilisateurs', '/familles'] as $page) {
            $this->client->request('GET', $page);
            self::assertResponseStatusCodeSame(403, $page);
            self::assertSelectorTextContains('h1', 'réservée à l\'encadrement');
        }
        $this->client->request('GET', '/');
        self::assertResponseRedirects('/espace');
        $this->client->request('GET', '/mon-compte');
        self::assertResponseIsSuccessful();

        // Le lien ne sert qu'une fois ; ensuite, connexion par e-mail et mot de passe.
        $this->client->restart();
        $this->client->request('GET', $lien);
        self::assertResponseStatusCodeSame(410);
        $crawler = $this->client->request('GET', '/connexion');
        $this->client->submit($crawler->selectButton('Se connecter')->form(), ['email' => $parent, 'mot_de_passe' => 'secret-famille']);
        $this->client->followRedirect();
        $this->client->followRedirect();
        self::assertRouteSame('app_espace');

        // --- Retrait : le compte reste tant qu'il suit un joueur, puis disparaît.
        $this->client->restart();
        $this->client->loginUser($this->compte("admin-$id@exemple.test"));
        foreach ([$lea, $tom] as $j) {
            $crawler = $this->client->request('GET', '/joueurs/' . $j->getId());
            $ligne = $crawler->filter('#famille li')->reduce(fn ($li) => str_contains($li->text(), $parent));
            $this->client->submit($ligne->filter('form[action$="/retirer"]')->form());
            if ($j === $lea) {
                self::assertCount(1, $this->compte($parent)->getJoueurs());
            }
        }
        self::assertNull($this->compte($parent));
        self::assertNotNull($this->compte("autre-$id@exemple.test"));
    }

    private function joueur(Club $club, Categorie $categorie, string $prenom, ?string $emailParent1, ?string $emailParent2 = null): Joueur
    {
        $joueur = (new Joueur())->setClub($club)->setCategorie($categorie)->setNom('Martin')->setPrenom($prenom)
            ->setEmailParent1($emailParent1)->setEmailParent2($emailParent2);
        $this->em()->persist($joueur);

        return $joueur;
    }

    /** Lien d'activation affiché après une invitation (bouton « Copier le message »). */
    private function lienAffiche(): string
    {
        $texte = $this->client->getCrawler()->filter('.liens-famille [data-copier-texte-value]')->attr('data-copier-texte-value');
        self::assertMatchesRegularExpression('#/activation/[0-9a-f]{48}#', $texte);
        preg_match('#https?://[^\s]+/activation/[0-9a-f]{48}#', $texte, $m);

        return $m[0];
    }

    private function jeton(string $page, string $formulaire): string
    {
        return $this->client->request('GET', $page)->filter($formulaire . ' input[name="_token"]')->attr('value');
    }

    private function compte(string $email): ?Utilisateur
    {
        $em = $this->em();
        $em->clear();

        return $em->getRepository(Utilisateur::class)->findOneBy(['email' => $email]);
    }

    private function em(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }
}
