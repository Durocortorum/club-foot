<?php

namespace App\Tests\Controller;

use App\Entity\Categorie;
use App\Entity\Club;
use App\Entity\Convocation;
use App\Entity\Convoque;
use App\Entity\Joueur;
use App\Entity\Message;
use App\Entity\Rencontre;
use App\Entity\Utilisateur;
use App\Service\CreationClub;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Mime\Email;

/**
 * Messagerie interne : l'encadrement écrit à une catégorie ou aux convoqués d'un match ; la famille lit
 * (information importante en tête de son espace) et répond dans un fil privé ; l'encadrement répond.
 * Club fictif supprimé à la fin.
 */
class MessagerieTest extends WebTestCase
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

    public function testParcoursComplet(): void
    {
        $id = bin2hex(random_bytes(4));
        $admin = (new Utilisateur())->setEmail("admin-$id@exemple.test")->setNom('Dupont')->setPrenom('Admin');
        $club = (new Club())->setNom("Club Test $id");
        static::getContainer()->get(CreationClub::class)->creer($club, $admin, 'motdepasse-admin');
        $this->clubId = $club->getId();
        $em = $this->em();
        $categorie = $em->getRepository(Categorie::class)->findOneBy(['club' => $club], ['ordre' => 'ASC']);
        $lea = (new Joueur())->setClub($club)->setCategorie($categorie)->setNom('Martin')->setPrenom('Léa');
        $tom = (new Joueur())->setClub($club)->setCategorie($categorie)->setNom('Durand')->setPrenom('Tom');
        $em->persist($lea);
        $em->persist($tom);
        $parent = (new Utilisateur())->setClub($club)->setFamille(true)->setEmail("parent-$id@exemple.test")->setNom('Martin')->setPrenom('Famille')->setPassword('x')->addJoueur($lea);
        $em->persist($parent);
        // Éducateur d'un autre secteur : ne voit pas les messages de cette catégorie.
        $autre = (new Utilisateur())->setClub($club)->setEmail("pro-$id@exemple.test")->setNom('Test')->setPrenom('Pro')->setRolesAttribues(['ROLE_PRO'])->setPassword('x');
        $em->persist($autre);
        $match = (new Rencontre())->setClub($club)->setCategorie($categorie)->setDate(new \DateTimeImmutable('+4 days'))->setDomicile("CLUB $id")->setExterieur('Reims');
        $em->persist($match);
        $convocation = (new Convocation($match))->setClub($club)->setJoueurs([$lea, $tom]);
        foreach ($convocation->getConvoques() as $c) {
            $c->setClub($club);
            if ($c->getJoueur() === $lea) {
                $c->repondre(Convoque::PRESENT, null, 'test');
            }
        }
        $em->persist($convocation);
        $em->flush();

        // --- Depuis la convocation : « Écrire aux convoqués », seulement ceux qui n'ont pas répondu (Tom, sans compte).
        $this->client->loginUser($admin);
        $crawler = $this->client->request('GET', '/matchs/' . $match->getId() . '/convocation');
        $crawler = $this->client->click($crawler->selectLink('Écrire aux convoqués')->link());
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('input[name="form[cible]"][value="convocation"][checked]');
        $this->client->submit($crawler->selectButton('Envoyer')->form(), [
            'form[filtre]' => 'sans_reponse', 'form[sujet]' => 'Réponse attendue', 'form[corps]' => 'Merci de répondre à la convocation.',
        ]);
        self::assertResponseRedirects();
        $this->client->followRedirect();
        self::assertSelectorTextContains('.flash-succes', 'Message envoyé à 1 joueur');
        self::assertSelectorTextContains('main', 'aucun compte famille');
        self::assertSelectorTextContains('main', 'Tom Durand');
        self::assertSelectorExists('[data-controller="copier"]');
        $sansReponse = $this->dernierMessage();

        // --- Information importante à la catégorie : un e-mail au parent de Léa.
        $crawler = $this->client->request('GET', '/messages/nouveau?categorie=' . $categorie->getId());
        $form = $crawler->selectButton('Envoyer')->form();
        $this->client->submit($form, [
            'form[cible]' => 'categorie', 'form[sujet]' => 'Tournoi annulé', 'form[corps]' => "Le tournoi de samedi est annulé.\n\nÀ bientôt.",
            'form[important]' => true, 'form[email]' => true,
        ]);
        self::assertResponseRedirects();
        $emails = $this->emails("parent-$id@exemple.test");
        self::assertCount(1, $emails);
        self::assertStringContainsString('IMPORTANT – Tournoi annulé', $emails[0]->getSubject());
        $important = $this->dernierMessage();
        self::assertCount(2, $important->getJoueurs());

        // Formulaire incomplet : erreurs, rien n'est envoyé.
        $crawler = $this->client->request('GET', '/messages/nouveau');
        $this->client->submit($crawler->selectButton('Envoyer')->form(), ['form[cible]' => 'joueurs', 'form[sujet]' => '', 'form[corps]' => '']);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('main', 'Cochez au moins un joueur');

        // L'éducateur d'un autre secteur ne voit pas ces messages.
        $this->client->loginUser($autre);
        $this->client->request('GET', '/messages');
        self::assertSelectorNotExists('a[href="/messages/' . $important->getId() . '"]');
        $this->client->request('GET', '/messages/' . $important->getId());
        self::assertResponseStatusCodeSame(403);

        // --- La famille : bandeau « Important », pastille, boîte de réception.
        $this->client->loginUser($parent);
        $this->client->request('GET', '/espace');
        self::assertSelectorTextContains('.info-importante', 'Tournoi annulé');
        self::assertSelectorTextContains('nav.menu', 'Messages 1');
        $this->client->request('GET', '/espace/messages');
        self::assertSelectorTextContains('.boite-reception .non-lu', 'Tournoi annulé');
        self::assertSelectorTextNotContains('.boite-reception', 'Réponse attendue');
        $crawler = $this->client->request('GET', '/espace/messages/' . $important->getId());
        self::assertSelectorTextContains('.message-corps', 'Le tournoi de samedi est annulé.');
        self::assertSelectorTextContains('main', 'pour Léa');
        // Lu : plus de bandeau.
        $this->client->request('GET', '/espace');
        self::assertSelectorNotExists('.info-importante');
        // Un message qui ne lui est pas adressé, et les pages de l'encadrement : refusés.
        $this->client->request('GET', '/espace/messages/' . $sansReponse->getId());
        self::assertResponseStatusCodeSame(404);
        $this->client->request('GET', '/messages');
        self::assertResponseStatusCodeSame(403);

        // Elle répond : e-mail à l'auteur, pastille côté encadrement.
        $crawler = $this->client->request('GET', '/espace/messages/' . $important->getId());
        $this->client->submit($crawler->selectButton('Envoyer')->form(), ['corps' => 'Bien reçu, merci.']);
        self::assertResponseRedirects();
        self::assertCount(1, $this->emails("admin-$id@exemple.test"));

        // --- L'encadrement lit la réponse (pastille), puis répond dans le fil de cette famille.
        $this->client->loginUser($admin);
        $this->client->request('GET', '/messages');
        self::assertSelectorTextContains('nav.menu', 'Messages 1');
        self::assertSelectorTextContains('table', '1 nouvelle');
        // La réponse remonte aussi en tête, avec les messages reçus.
        self::assertSelectorTextContains('.boite-reception li:first-child', 'Tournoi annulé');
        self::assertSelectorTextContains('.boite-reception li:first-child', '1 nouvelle réponse');
        $crawler = $this->client->request('GET', '/messages/' . $important->getId());
        self::assertSelectorTextContains('#fil-' . $parent->getId(), 'Bien reçu, merci.');
        self::assertSelectorTextContains('#fil-' . $parent->getId() . ' h3', 'Parent de Léa');
        self::assertSelectorTextNotContains('#fil-' . $parent->getId(), 'Famille ·');
        self::assertSelectorTextContains('main', 'lu');
        $this->client->submit($crawler->filter('#fil-' . $parent->getId() . ' form')->form(), ['corps' => 'Parfait, bonne journée.']);
        self::assertResponseRedirects();
        $emails = $this->emails("parent-$id@exemple.test");
        self::assertCount(1, $emails);
        self::assertStringContainsString('réponse à « Tournoi annulé »', $emails[0]->getSubject());
        $this->client->request('GET', '/messages');
        self::assertSelectorNotExists('nav.menu .pastille.alerte:not([hidden])');

        // La famille voit la réponse ; personne d'autre.
        $this->client->loginUser($parent);
        $this->client->request('GET', '/espace');
        self::assertSelectorTextContains('nav.menu', 'Messages 1');
        $this->client->request('GET', '/espace/messages/' . $important->getId());
        self::assertSelectorTextContains('#fil-' . $parent->getId(), 'Parfait, bonne journée.');

        // Suppression par l'auteur : la famille ne le voit plus.
        $this->client->loginUser($admin);
        $crawler = $this->client->request('GET', '/messages/' . $important->getId());
        $this->client->submit($crawler->filter('form[action$="/supprimer"]')->form());
        $this->client->loginUser($parent);
        $this->client->request('GET', '/espace/messages/' . $important->getId());
        self::assertResponseStatusCodeSame(404);
    }

    /** Le coach écrit à un collègue : lecture, réponse privée, et un tiers de l'encadrement n'y a pas accès. */
    public function testEntreMembresDeLEncadrement(): void
    {
        $id = bin2hex(random_bytes(4));
        $admin = (new Utilisateur())->setEmail("admin-$id@exemple.test")->setNom('Dupont')->setPrenom('Admin');
        $club = (new Club())->setNom("Club Test $id");
        static::getContainer()->get(CreationClub::class)->creer($club, $admin, 'motdepasse-admin');
        $this->clubId = $club->getId();
        $em = $this->em();
        $coach = (new Utilisateur())->setClub($club)->setEmail("coach-$id@exemple.test")->setNom('Bernard')->setPrenom('Coach')->setRolesAttribues(['ROLE_ECOLE_FOOT'])->setPassword('x');
        // Kiné : compte « médical seul » (Infirmerie), qui a aussi sa page Messages.
        $kine = (new Utilisateur())->setClub($club)->setEmail("kine-$id@exemple.test")->setNom('Petit')->setPrenom('Julie')->setRolesAttribues(['ROLE_INFIRMERIE'])->setPassword('x');
        $tiers = (new Utilisateur())->setClub($club)->setEmail("tiers-$id@exemple.test")->setNom('Roux')->setPrenom('Paul')->setRolesAttribues(['ROLE_PRO'])->setPassword('x');
        foreach ([$coach, $kine, $tiers] as $u) {
            $em->persist($u);
        }
        $em->flush();

        $this->client->loginUser($coach);
        $crawler = $this->client->request('GET', '/messages/nouveau');
        self::assertSelectorExists('input[name="form[cible]"][value="encadrement"]');
        self::assertSelectorNotExists('input[name="form[cible]"][value="club"]');
        $form = $crawler->selectButton('Envoyer')->form();
        $form['form[cible]']->select('encadrement');
        foreach ($form['form[personnes]'] as $case) {
            if ($case->availableOptionValues() === [(string) $kine->getId()]) {
                $case->tick();
            }
        }
        $this->client->submit($form, ['form[sujet]' => 'Léa : reprise ?', 'form[corps]' => 'Peut-elle reprendre samedi ?']);
        self::assertResponseRedirects();
        self::assertCount(1, $this->emails("kine-$id@exemple.test"));
        $this->client->followRedirect();
        self::assertSelectorTextContains('.flash-succes', 'Message envoyé à 1 personne');
        self::assertSelectorTextContains('main', 'Julie Petit');
        $message = $this->dernierMessage();

        // La kiné : pastille, « Reçus de collègues », lecture et réponse privée.
        $this->client->loginUser($kine);
        $this->client->request('GET', '/messages');
        self::assertSelectorTextContains('nav.menu', 'Messages 1');
        self::assertSelectorTextContains('.boite-reception .non-lu', 'Léa : reprise ?');
        $crawler = $this->client->request('GET', '/messages/' . $message->getId());
        self::assertSelectorTextContains('main', 'Votre conversation avec Coach Bernard');
        $this->client->submit($crawler->selectButton('Envoyer')->form(), ['corps' => 'Oui, sans contact.']);
        self::assertResponseRedirects('/messages/' . $message->getId() . '#fil-' . $kine->getId());
        self::assertCount(1, $this->emails("coach-$id@exemple.test"));
        $this->client->request('GET', '/messages');
        self::assertSelectorNotExists('nav.menu .pastille.alerte:not([hidden])');
        // Sans catégorie : elle n'écrit qu'aux collègues.
        $this->client->request('GET', '/messages/nouveau');
        self::assertSelectorExists('input[name="form[cible]"][value="encadrement"][checked]');
        self::assertSelectorNotExists('input[name="form[cible]"][value="categorie"]');

        // Un autre membre de l'encadrement : ni lecture, ni réponse.
        $this->client->loginUser($tiers);
        $this->client->request('GET', '/messages/' . $message->getId());
        self::assertResponseStatusCodeSame(403);

        // Le coach voit la réponse et répond ; la kiné la reçoit.
        $this->client->loginUser($coach);
        $this->client->request('GET', '/messages');
        self::assertSelectorTextContains('nav.menu', 'Messages 1');
        $crawler = $this->client->request('GET', '/messages/' . $message->getId());
        self::assertSelectorTextContains('#fil-' . $kine->getId(), 'Oui, sans contact.');
        self::assertSelectorTextContains('#fil-' . $kine->getId() . ' .bulle', 'Julie Petit');
        $this->client->submit($crawler->filter('#fil-' . $kine->getId() . ' form')->form(), ['corps' => 'Merci !']);
        self::assertCount(1, $this->emails("kine-$id@exemple.test"));
        $this->client->loginUser($kine);
        $this->client->request('GET', '/messages');
        self::assertSelectorTextContains('nav.menu', 'Messages 1');
        $this->client->request('GET', '/messages/' . $message->getId());
        self::assertSelectorTextContains('#fil-' . $kine->getId(), 'Merci !');
    }

    /** Un joueur (ou un parent) écrit à l'éducateur de sa catégorie : message privé, réponse dans son espace. */
    public function testFamilleEcritALEncadrement(): void
    {
        $id = bin2hex(random_bytes(4));
        $admin = (new Utilisateur())->setEmail("admin-$id@exemple.test")->setNom('Dupont')->setPrenom('Admin');
        $club = (new Club())->setNom("Club Test $id");
        static::getContainer()->get(CreationClub::class)->creer($club, $admin, 'motdepasse-admin');
        $this->clubId = $club->getId();
        $em = $this->em();
        $categorie = $em->getRepository(Categorie::class)->findOneBy(['club' => $club], ['ordre' => 'ASC']);
        // Joueur majeur avec son propre compte (adresse « joueur » de sa fiche).
        $nico = (new Joueur())->setClub($club)->setCategorie($categorie)->setNom('Descotes')->setPrenom('Nicolas')->setEmailJoueur("nico-$id@exemple.test");
        $em->persist($nico);
        $compte = (new Utilisateur())->setClub($club)->setFamille(true)->setEmail("nico-$id@exemple.test")->setNom('Descotes')->setPrenom('Nicolas')->setPassword('x')->addJoueur($nico);
        $coach = (new Utilisateur())->setClub($club)->setEmail("coach-$id@exemple.test")->setNom('Bernard')->setPrenom('Coach')->setRolesAttribues(['ROLE_ECOLE_FOOT'])->setPassword('x');
        $autre = (new Utilisateur())->setClub($club)->setEmail("pro-$id@exemple.test")->setNom('Roux')->setPrenom('Paul')->setRolesAttribues(['ROLE_PRO'])->setPassword('x');
        // Corps médical : médecin du club et kiné (compte qui n'a que l'Infirmerie).
        $medecin = (new Utilisateur())->setClub($club)->setEmail("medecin-$id@exemple.test")->setNom('Morel')->setPrenom('Docteur')->setRolesAttribues(['ROLE_MEDECIN'])->setPassword('x');
        $kine = (new Utilisateur())->setClub($club)->setEmail("kine-$id@exemple.test")->setNom('Petit')->setPrenom('Julie')->setRolesAttribues(['ROLE_INFIRMERIE'])->setPassword('x');
        foreach ([$compte, $coach, $autre, $medecin, $kine] as $u) {
            $em->persist($u);
        }
        $em->flush();

        // Le joueur : éducateurs de sa catégorie, corps médical et direction, pas les autres secteurs.
        $this->client->loginUser($compte);
        $crawler = $this->client->request('GET', '/espace/messages');
        $crawler = $this->client->click($crawler->selectLink('+ Écrire à l\'encadrement')->link());
        self::assertSelectorExists('input[name="a[]"][value="' . $coach->getId() . '"]');
        self::assertSelectorExists('input[name="a[]"][value="' . $admin->getId() . '"]');
        self::assertSelectorNotExists('input[name="a[]"][value="' . $autre->getId() . '"]');
        self::assertSelectorTextContains('main', 'Encadrement ' . $categorie->getNom());
        $medical = $crawler->filter('fieldset')->reduce(fn ($f) => str_contains($f->filter('legend')->text(), 'Corps médical'));
        self::assertCount(1, $medical);
        self::assertCount(2, $medical->filter('input[name="a[]"]'));
        self::assertSelectorExists('input[name="a[]"][value="' . $kine->getId() . '"]');
        // Destinataire hors liste : refusé.
        $jeton = $crawler->filter('input[name="_token"]')->attr('value');
        $this->client->request('POST', '/espace/messages/nouveau', ['_token' => $jeton, 'a' => [$autre->getId()], 'sujet' => 'x', 'corps' => 'y']);
        self::assertResponseStatusCodeSame(422);
        $this->client->request('POST', '/espace/messages/nouveau', ['_token' => $jeton, 'a' => [$coach->getId()], 'sujet' => 'Absent samedi', 'corps' => 'Je serai absent samedi.']);
        self::assertResponseRedirects();
        self::assertCount(1, $this->emails("coach-$id@exemple.test"));
        $message = $this->dernierMessage();
        self::assertSame('Nicolas Descotes (joueur)', $message->getAuteurNom());

        // Côté encadrement : le corps médical forme aussi un groupe dans les destinataires.
        $this->client->loginUser($coach);
        $crawler = $this->client->request('GET', '/messages/nouveau');
        $groupe = $crawler->filter('[data-cible="encadrement"] fieldset')->reduce(fn ($f) => str_contains($f->filter('legend')->text(), 'Corps médical'));
        self::assertCount(2, $groupe->filter('input'));

        // Le coach le reçoit (pastille, « Reçus de collègues »... de la famille), et répond.
        $this->client->loginUser($coach);
        $this->client->request('GET', '/messages');
        self::assertSelectorTextContains('nav.menu', 'Messages 1');
        self::assertSelectorTextContains('.boite-reception', 'Absent samedi');
        $crawler = $this->client->request('GET', '/messages/' . $message->getId());
        self::assertSelectorTextContains('main', 'Votre conversation avec Nicolas Descotes (joueur)');
        $this->client->submit($crawler->selectButton('Envoyer')->form(), ['corps' => 'Merci de prévenir.']);
        self::assertCount(1, $this->emails("nico-$id@exemple.test"));

        // Privé : ni l'administrateur (non destinataire), ni un autre éducateur.
        foreach ([$admin, $autre] as $u) {
            $this->client->loginUser($u);
            $this->client->request('GET', '/messages/' . $message->getId());
            self::assertResponseStatusCodeSame(403);
            $this->client->request('GET', '/messages');
            self::assertSelectorTextNotContains('main', 'Absent samedi');
        }

        // État demandé par la page (mise à jour sans recharger) : rien sur un message qu'on ne voit pas.
        $this->client->request('GET', '/espace/messages/etat?message=' . $message->getId());
        self::assertSame(['nonLus' => 0], json_decode($this->client->getResponse()->getContent(), true));

        // Le joueur voit la réponse, et peut répondre encore.
        $this->client->loginUser($compte);
        $this->client->request('GET', '/espace/messages/etat?message=' . $message->getId());
        self::assertSame(['nonLus' => 1, 'fil' => 1], json_decode($this->client->getResponse()->getContent(), true));
        $this->client->request('GET', '/espace/messages');
        self::assertSelectorTextContains('nav.menu', 'Messages 1');
        self::assertSelectorTextContains('.boite-reception li:first-child', '1 nouvelle réponse');
        $crawler = $this->client->request('GET', '/espace/messages/' . $message->getId());
        self::assertSelectorTextContains('#fil-' . $coach->getId(), 'Merci de prévenir.');
        $this->client->submit($crawler->filter('#fil-' . $coach->getId() . ' form')->form(), ['corps' => 'Avec plaisir.']);
        self::assertResponseRedirects('/espace/messages/' . $message->getId() . '#fil-' . $coach->getId());
        $this->client->request('GET', '/espace/messages');
        self::assertSelectorNotExists('nav.menu .pastille:not([hidden])');
    }

    private function dernierMessage(): Message
    {
        $this->em()->clear();

        return $this->em()->getRepository(Message::class)->findOneBy(['club' => $this->clubId], ['id' => 'DESC']);
    }

    /** @return list<Email> e-mails préparés pour cette adresse pendant la dernière requête */
    private function emails(string $adresse): array
    {
        return array_values(array_filter(self::getMailerMessages(), fn ($m) => $m instanceof Email
            && \in_array($adresse, array_map(fn ($a) => $a->getAddress(), $m->getTo()), true)));
    }

    private function em(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }
}
