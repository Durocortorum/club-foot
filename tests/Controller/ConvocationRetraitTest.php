<?php

namespace App\Tests\Controller;

use App\Entity\Categorie;
use App\Entity\Club;
use App\Entity\Joueur;
use App\Entity\Rencontre;
use App\Entity\Utilisateur;
use App\Repository\ConvocationRepository;
use App\Service\CreationClub;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Joueur retiré d'une convocation déjà envoyée (erreur, décision du staff) : il disparaît de la convocation
 * et sa famille est prévenue (e-mail et messagerie interne). Club fictif supprimé à la fin.
 */
class ConvocationRetraitTest extends WebTestCase
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
            $connexion = $this->em()->getConnection();
            foreach (['message', 'rencontre', 'joueur', 'club'] as $table) {
                $connexion->executeStatement("DELETE FROM $table WHERE " . ('club' === $table ? 'id' : 'club_id') . ' = ?', [$this->clubId]);
            }
        }
        parent::tearDown();
    }

    public function testRetirerUnJoueurConvoque(): void
    {
        $id = bin2hex(random_bytes(4));
        $admin = (new Utilisateur())->setEmail("admin-$id@exemple.test")->setNom('Test')->setPrenom('Coach');
        $club = (new Club())->setNom("Club Test $id");
        static::getContainer()->get(CreationClub::class)->creer($club, $admin, 'motdepasse-admin');
        $this->clubId = $club->getId();
        $em = $this->em();
        $categorie = $em->getRepository(Categorie::class)->findOneBy(['club' => $club], ['ordre' => 'ASC']);
        $lea = (new Joueur())->setClub($club)->setCategorie($categorie)->setNom('Martin')->setPrenom('Léa');
        $tom = (new Joueur())->setClub($club)->setCategorie($categorie)->setNom('Durand')->setPrenom('Tom');
        $max = (new Joueur())->setClub($club)->setCategorie($categorie)->setNom('Petit')->setPrenom('Max');
        $em->persist($lea);
        $em->persist($tom);
        $em->persist($max);
        $parentTom = (new Utilisateur())->setClub($club)->setFamille(true)->setEmail("parent-$id@exemple.test")->setNom('Durand')->setPrenom('Famille')->setPassword('x')->addJoueur($tom);
        $em->persist($parentTom);
        $match = (new Rencontre())->setClub($club)->setCategorie($categorie)->setDate(new \DateTimeImmutable('+5 days'))->setDomicile("CLUB $id")->setExterieur('Sedan');
        $em->persist($match);
        $em->flush();
        $page = '/matchs/' . $match->getId() . '/convocation';

        // Léa et Tom convoqués, convocation envoyée.
        $this->client->loginUser($admin);
        $crawler = $this->client->request('GET', $page);
        $form = $crawler->selectButton('Enregistrer sans envoyer')->form();
        foreach ($form['form[joueurs]'] as $case) {
            $case->tick();
        }
        $this->client->submit($form);
        $crawler = $this->client->followRedirect();
        $this->client->submit($crawler->selectButton('Enregistrer et envoyer')->form());
        $crawler = $this->client->followRedirect();

        // Tom décoché, enregistré : il n'est plus convoqué.
        $form = $crawler->filter('form[name="form"]')->form();
        foreach ($form['form[joueurs]'] as $case) {
            if ($case->availableOptionValues() === [(string) $tom->getId()]) {
                $case->untick();
            }
        }
        $this->client->submit($form);
        self::assertResponseRedirects();
        // Sa famille est prévenue : e-mail et messagerie interne (sans bouton « présent / absent »).
        $emails = array_values(array_filter(self::getMailerMessages(), fn ($m) => str_contains((string) $m->getSubject(), 'Changement de convocation')));
        self::assertCount(1, $emails);
        self::assertSame("parent-$id@exemple.test", $emails[0]->getTo()[0]->getAddress());
        self::assertSame(['Léa', 'Max'], $this->convoques($match));
        $crawler = $this->client->followRedirect();
        self::assertSelectorTextContains('.flash-info', 'prévenue du retrait');

        // Max retiré avec le bouton « Retirer » (sans compte famille : signalé pour un appel ou un SMS).
        $ligne = $crawler->filter('#reponses tr')->reduce(fn ($tr) => str_contains($tr->text(), 'Max'));
        $this->client->submit($ligne->filter('form[action*="/retirer/"]')->form());
        self::assertSame(['Léa'], $this->convoques($match));
        $this->client->followRedirect();
        self::assertSelectorTextContains('main', 'à prévenir par téléphone ou SMS : Max PETIT');

        // Léa, dernière convoquée : on ne la retire pas, on supprime la convocation.
        $crawler = $this->client->request('GET', $page);
        $this->client->submit($crawler->filter('#reponses form[action*="/retirer/"]')->form());
        $this->client->followRedirect();
        self::assertSelectorTextContains('.flash-erreur', 'seul joueur convoqué');
        self::assertSame(['Léa'], $this->convoques($match));

        // Côté famille de Tom : le message de changement, sans bouton de réponse à la convocation.
        $this->client->loginUser($parentTom);
        $this->client->request('GET', '/espace/messages');
        self::assertSelectorTextContains('.boite-reception li:first-child', 'Changement de convocation');
        $this->client->click($this->client->getCrawler()->filter('.boite-reception li:first-child a')->link());
        self::assertSelectorTextContains('main', 'pour Tom');
        self::assertSelectorNotExists('a:contains("Répondre présent ou absent")');
    }

    /** @return list<string> prénoms des joueurs convoqués à ce match */
    private function convoques(Rencontre $match): array
    {
        $this->em()->clear();
        $convocation = static::getContainer()->get(ConvocationRepository::class)->pour($this->em()->find(Rencontre::class, $match->getId()));

        return array_map(fn (Joueur $j) => $j->getPrenom(), $convocation->getJoueurs());
    }

    private function em(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }
}
