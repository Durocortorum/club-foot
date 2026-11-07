<?php

namespace App\Tests\Controller;

use App\Entity\Blessure;
use App\Entity\Categorie;
use App\Entity\Club;
use App\Entity\Joueur;
use App\Entity\Utilisateur;
use App\Service\CreationClub;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Données médicales : droit donné par un médecin (ou l'administrateur) avec échéance,
 * puis consultation ouverte par un code reçu par e-mail. Club fictif supprimé à la fin.
 */
class AccesMedicalTest extends WebTestCase
{
    use OuvertureMedicaleTrait;

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
            foreach (['rencontre', 'joueur', 'club'] as $table) {
                $connexion->executeStatement("DELETE FROM $table WHERE " . ('club' === $table ? 'id' : 'club_id') . ' = ?', [$this->clubId]);
            }
        }
        parent::tearDown();
    }

    public function testCodeEtEcheance(): void
    {
        $id = bin2hex(random_bytes(4));
        $admin = (new Utilisateur())->setEmail("admin-$id@exemple.test")->setNom('Test')->setPrenom('Admin');
        $club = (new Club())->setNom("Club Test $id");
        static::getContainer()->get(CreationClub::class)->creer($club, $admin, 'motdepasse-admin');
        $this->clubId = $club->getId();
        $em = $this->em();
        $categorie = $em->getRepository(Categorie::class)->findOneBy(['club' => $club], ['ordre' => 'ASC']);
        $joueur = (new Joueur())->setClub($club)->setCategorie($categorie)->setNom('Martin')->setPrenom('Léo');
        $em->persist($joueur);
        $em->persist((new Blessure())->setClub($club)->setJoueur($joueur)->setDate(new \DateTimeImmutable('-3 days'))->setType('Entorse')->setLocalisation('Cheville'));
        $medecin = $this->compteEncadrement($club, "medecin-$id@exemple.test", 'Docteur', ['ROLE_MEDECIN']);
        $kine = $this->compteEncadrement($club, "kine-$id@exemple.test", 'Kiné', []);
        $em->flush();

        // --- Le médecin ouvre le tableau des droits médicaux (pas les autres pages d'administration).
        $this->client->loginUser($medecin);
        $crawler = $this->client->request('GET', '/utilisateurs/droits');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Droits médicaux');
        $this->client->request('GET', '/utilisateurs');
        self::assertResponseStatusCodeSame(403);
        $jeton = $crawler->filter('input[name="_token"]')->attr('value');

        // Il donne « Infirmerie : modifier » au kiné pour 1 semaine ; il ne peut pas toucher aux secteurs.
        foreach (['1', '2'] as $niveau) {
            $this->client->request('POST', '/utilisateurs/' . $kine->getId() . '/droit',
                ['droit' => 'infirmerie', 'niveau' => $niveau, 'duree' => '7', '_token' => $jeton], [], ['HTTP_ACCEPT' => 'application/json']);
            self::assertResponseIsSuccessful();
        }
        $reponse = json_decode($this->client->getResponse()->getContent(), true);
        self::assertSame((new \DateTimeImmutable('+7 days'))->format('d/m/Y'), $reponse['echeances']['infirmerie']);
        $this->client->request('POST', '/utilisateurs/' . $kine->getId() . '/droit',
            ['droit' => 'ROLE_FORMATION', 'niveau' => '1', '_token' => $jeton], [], ['HTTP_ACCEPT' => 'application/json']);
        self::assertResponseStatusCodeSame(422);
        $this->client->request('POST', '/utilisateurs/' . $kine->getId() . '/droit',
            ['droit' => 'visite_medicale', 'niveau' => '1', 'duree' => '9999', '_token' => $jeton], [], ['HTTP_ACCEPT' => 'application/json']);
        self::assertResponseStatusCodeSame(422);

        // --- Le kiné : l'infirmerie demande d'abord un code par e-mail.
        $this->client->loginUser($this->compte($kine));
        $this->client->request('GET', '/infirmerie');
        self::assertResponseRedirects('/medical/code?retour=/infirmerie');
        // Compte « médical seul » : pas de fiche joueur, son dossier médical demande aussi le code.
        $this->client->request('GET', '/joueurs/' . $joueur->getId());
        self::assertResponseStatusCodeSame(403);
        $this->client->request('GET', '/infirmerie/dossiers/' . $joueur->getId());
        self::assertResponseRedirects('/medical/code?retour=/infirmerie/dossiers/' . $joueur->getId());

        $crawler = $this->client->request('GET', '/medical/code?retour=/infirmerie');
        $this->client->submit($crawler->selectButton('Recevoir un code par e-mail')->form());
        $code = $this->codeRecu();
        $crawler = $this->client->followRedirect();
        $faux = '000000' === $code ? '111111' : '000000';
        $this->client->submit($crawler->selectButton('Ouvrir l\'accès')->form(), ['code' => $faux]);
        $crawler = $this->client->followRedirect();
        self::assertSelectorTextContains('.flash-erreur', 'Code incorrect');
        $this->client->submit($crawler->selectButton('Ouvrir l\'accès')->form(), ['code' => $code]);
        self::assertResponseRedirects('/infirmerie');
        $this->client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.medical-ouvert', 'Médical jusqu');
        $this->client->request('GET', '/infirmerie/dossiers/' . $joueur->getId());
        self::assertSelectorTextContains('#infirmerie', 'Entorse');

        // Il referme : nouveau code nécessaire.
        $crawler = $this->client->request('GET', '/infirmerie');
        $this->client->submit($crawler->filter('.medical-ouvert')->form());
        $this->client->request('GET', '/infirmerie');
        self::assertResponseRedirects('/medical/code?retour=/infirmerie');

        // --- Droit échu : plus d'accès du tout, même avec un code.
        $this->em()->getConnection()->executeStatement('UPDATE utilisateur SET infirmerie_jusqu_au = ? WHERE id = ?', [(new \DateTimeImmutable('-1 day'))->format('Y-m-d'), $kine->getId()]);
        $this->client->loginUser($this->compte($kine));
        $this->client->request('GET', '/infirmerie');
        self::assertResponseStatusCodeSame(403);
        $this->client->request('GET', '/medical/code');
        self::assertResponseStatusCodeSame(403);

        // --- L'administrateur (non médecin) garde l'accès, lui aussi par code.
        $this->client->loginUser($admin);
        $this->client->request('GET', '/infirmerie');
        self::assertResponseRedirects();
        $this->ouvrirMedical($this->client);
        $this->client->request('GET', '/infirmerie');
        self::assertResponseIsSuccessful();
    }

    public function testCompteMedecinDepuisUnContact(): void
    {
        $id = bin2hex(random_bytes(4));
        $admin = (new Utilisateur())->setEmail("admin-$id@exemple.test")->setNom('Test')->setPrenom('Admin');
        $club = (new Club())->setNom("Club Test $id");
        static::getContainer()->get(CreationClub::class)->creer($club, $admin, 'motdepasse-admin');
        $this->clubId = $club->getId();
        $contact = (new \App\Entity\ContactMedical())->setClub($club)->setNom('Durand')->setPrenom('Claire')->setProfession('Médecin');
        $this->em()->persist($contact);
        $this->em()->flush();

        // L'administrateur (après le code médical) crée le compte depuis le contact.
        $this->client->loginUser($admin);
        $this->ouvrirMedical($this->client);
        $crawler = $this->client->request('GET', '/infirmerie/contacts');
        $this->client->click($crawler->selectLink('Compte médecin')->link());
        $email = "claire-$id@exemple.test";
        $this->client->submitForm('Enregistrer', ['form[email]' => $email]);
        self::assertEmailCount(1);
        $message = self::getMailerMessage();
        self::assertInstanceOf(\Symfony\Component\Mime\Email::class, $message);
        self::assertSame(1, preg_match('#https?://\S+/mot-de-passe/[0-9a-f]{48}#', $message->getTextBody(), $m));
        $medecin = $this->em()->getRepository(Utilisateur::class)->findOneBy(['email' => $email]);
        self::assertTrue($medecin->isMedecin());
        self::assertSame('Claire', $medecin->getPrenom());
        self::assertNull($medecin->getPassword());

        // Le médecin choisit son mot de passe (lien de l'e-mail) et accède aux droits médicaux.
        $this->client->restart();
        $crawler = $this->client->request('GET', $m[0]);
        $this->client->submit($crawler->selectButton('Enregistrer et me connecter')->form(), [
            'form[motDePasse][first]' => 'secret-medecin', 'form[motDePasse][second]' => 'secret-medecin',
        ]);
        $this->client->followRedirect();
        $this->client->request('GET', '/utilisateurs/droits');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Droits médicaux');
    }

    public function testDemandeDAcces(): void
    {
        $id = bin2hex(random_bytes(4));
        $admin = (new Utilisateur())->setEmail("admin-$id@exemple.test")->setNom('Test')->setPrenom('Admin');
        $club = (new Club())->setNom("Club Test $id");
        static::getContainer()->get(CreationClub::class)->creer($club, $admin, 'motdepasse-admin');
        $this->clubId = $club->getId();
        $categorie = $this->em()->getRepository(Categorie::class)->findOneBy(['club' => $club], ['ordre' => 'ASC']);
        $joueur = (new Joueur())->setClub($club)->setCategorie($categorie)->setNom('Martin')->setPrenom('Léo');
        $this->em()->persist($joueur);
        $medecin = $this->compteEncadrement($club, "medecin-$id@exemple.test", 'Docteur', ['ROLE_MEDECIN']);
        $coach = $this->compteEncadrement($club, "coach-$id@exemple.test", 'Coach', [Categorie::SECTEURS[$categorie->getSecteur()]['role']]);
        $this->em()->flush();

        // Le coach, sans droit médical, trouve le lien sur la fiche et fait sa demande.
        $this->client->loginUser($coach);
        $crawler = $this->client->request('GET', '/joueurs/' . $joueur->getId());
        $crawler = $this->client->click($crawler->selectLink('🔒 Demander l\'accès médical')->link());
        $this->client->submit($crawler->selectButton('Envoyer la demande')->form(), [
            'form[section]' => 'infirmerie', 'form[niveau]' => '2', 'form[motif]' => 'Suivi de la blessure de Léo',
        ]);
        self::assertEmailCount(2); // le médecin et l'administrateur
        self::assertStringContainsString('Suivi de la blessure de Léo', self::getMailerMessage()->getTextBody());
        $crawler = $this->client->followRedirect();
        self::assertSelectorTextContains('.flash-succes', 'médecin du club');
        self::assertSelectorTextContains('main', 'en attente');
        self::assertSelectorTextNotContains('main', 'Docteur'); // le nom du médecin n'est jamais montré

        // Une deuxième demande identique est refusée.
        $this->client->submit($crawler->selectButton('Envoyer la demande')->form(), ['form[section]' => 'infirmerie', 'form[niveau]' => '1']);
        self::assertSelectorTextContains('main', 'déjà une demande en attente');

        // Le médecin voit la demande (pastille du menu) et l'accorde pour 1 semaine.
        $this->client->loginUser($medecin);
        $crawler = $this->client->request('GET', '/utilisateurs/droits');
        self::assertSelectorTextContains('a[title="Donner les droits médicaux"] .pastille', '1');
        self::assertSelectorTextContains('#demandes', 'Suivi de la blessure de Léo');
        $this->client->submit($crawler->filter('#demandes')->selectButton('Accorder')->form(), ['duree' => '7']);
        self::assertEmailCount(1);
        self::assertStringContainsString('accordée jusqu\'au ' . (new \DateTimeImmutable('+7 days'))->format('d/m/Y'), self::getMailerMessage()->getTextBody());
        $coach = $this->compte($coach);
        self::assertSame(2, $coach->getNiveau('infirmerie'));
        self::assertSame((new \DateTimeImmutable('+7 days'))->format('Y-m-d'), $coach->getEcheance('infirmerie')->format('Y-m-d'));

        // Demande de visite médicale, refusée : le coach est prévenu, rien n'est accordé.
        $this->client->loginUser($coach);
        $crawler = $this->client->request('GET', '/acces-medical/demande');
        $this->client->submit($crawler->selectButton('Envoyer la demande')->form(), ['form[section]' => 'visite_medicale', 'form[niveau]' => '1']);
        $this->client->loginUser($this->compte($medecin));
        $crawler = $this->client->request('GET', '/utilisateurs/droits');
        $this->client->submit($crawler->filter('#demandes')->selectButton('Refuser')->form());
        self::assertStringContainsString('n\'a pas été accordée', self::getMailerMessage()->getTextBody());
        self::assertSame(0, $this->compte($coach)->getNiveau('visite_medicale'));

        // Un compte sans droit de traitement ne peut pas accorder.
        $this->client->loginUser($this->compte($coach));
        $autre = $this->em()->getRepository(\App\Entity\DemandeAccesMedical::class)->findOneBy([], ['id' => 'DESC']);
        $this->client->request('POST', '/acces-medical/demande/' . $autre->getId() . '/accorder', ['_token' => 'x']);
        self::assertResponseStatusCodeSame(403);
    }

    public function testCompteMedicalSeul(): void
    {
        $id = bin2hex(random_bytes(4));
        $admin = (new Utilisateur())->setEmail("admin-$id@exemple.test")->setNom('Test')->setPrenom('Admin');
        $club = (new Club())->setNom("Club Test $id");
        static::getContainer()->get(CreationClub::class)->creer($club, $admin, 'motdepasse-admin');
        $this->clubId = $club->getId();
        $categorie = $this->em()->getRepository(Categorie::class)->findOneBy(['club' => $club], ['ordre' => 'ASC']);
        $joueur = (new Joueur())->setClub($club)->setCategorie($categorie)->setNom('Martin')->setPrenom('Léo');
        $this->em()->persist($joueur);
        $this->em()->persist((new Blessure())->setClub($club)->setJoueur($joueur)->setDate(new \DateTimeImmutable('-3 days'))->setType('Entorse')->setLocalisation('Cheville'));
        $medecin = $this->compteEncadrement($club, "medecin-$id@exemple.test", 'Docteur', ['ROLE_MEDECIN']);
        $coach = $this->compteEncadrement($club, "coach-$id@exemple.test", 'Coach', [Categorie::SECTEURS[$categorie->getSecteur()]['role'], 'ROLE_INFIRMERIE']);
        $coach->setEcheance('infirmerie', new \DateTimeImmutable('+30 days'));
        $this->em()->flush();
        self::assertTrue($medecin->isMedicalSeul());
        self::assertFalse($coach->isMedicalSeul());

        // Le médecin : l'accueil mène à l'Infirmerie ; les pages de l'encadrement lui sont fermées.
        $this->client->loginUser($medecin);
        $this->client->request('GET', '/');
        self::assertResponseRedirects('/infirmerie');
        foreach (['/joueurs', '/joueurs/' . $joueur->getId(), '/matchs', '/statistiques', '/presences', '/scolarite', '/utilisateurs'] as $page) {
            $this->client->request('GET', $page);
            self::assertResponseStatusCodeSame(403, $page);
        }

        // Après le code : Infirmerie et dossiers médicaux, avec un menu réduit.
        $this->ouvrirMedical($this->client);
        $crawler = $this->client->request('GET', '/infirmerie/dossiers');
        self::assertResponseIsSuccessful();
        self::assertSame(['Infirmerie', 'Dossiers médicaux'], $crawler->filter('nav.menu a')->each(fn ($a) => trim($a->text())));
        $crawler = $this->client->click($crawler->selectLink('Martin')->link());
        self::assertSelectorTextContains('#infirmerie', 'Entorse');
        self::assertSelectorExists('#visite-medicale');
        $this->client->click($crawler->selectLink('Modifier')->link());
        $this->client->submitForm('Enregistrer', ['renseignements_medicaux[medecin]' => 'Dr Durand']);
        self::assertResponseRedirects('/infirmerie/dossiers/' . $joueur->getId());
        $this->client->followRedirect();
        self::assertSelectorTextContains('main', 'Dr Durand');
        $this->client->request('GET', '/utilisateurs/droits');
        self::assertResponseIsSuccessful();

        // Un éducateur qui a aussi l'Infirmerie garde le menu complet et sa fiche joueur.
        $this->client->loginUser($this->compte($coach));
        $this->client->request('GET', '/joueurs/' . $joueur->getId());
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('nav.menu a[href="/matchs"]');
    }

    public function testPerimetreDeLEducateur(): void
    {
        $id = bin2hex(random_bytes(4));
        $admin = (new Utilisateur())->setEmail("admin-$id@exemple.test")->setNom('Test')->setPrenom('Admin');
        $club = (new Club())->setNom("Club Test $id");
        static::getContainer()->get(CreationClub::class)->creer($club, $admin, 'motdepasse-admin');
        $this->clubId = $club->getId();
        $em = $this->em();
        $categories = $em->getRepository(Categorie::class)->findBy(['club' => $club], ['ordre' => 'ASC']);
        $parSecteur = [];
        foreach ($categories as $c) {
            $parSecteur[$c->getSecteur()] ??= $c;
        }
        [$a, $b] = array_values($parSecteur);
        $chez = (new Joueur())->setClub($club)->setCategorie($a)->setNom('Dedans')->setPrenom('Léo');
        $ailleurs = (new Joueur())->setClub($club)->setCategorie($b)->setNom('Dehors')->setPrenom('Tom');
        foreach ([$chez, $ailleurs] as $j) {
            $em->persist($j);
            $em->persist((new Blessure())->setClub($club)->setJoueur($j)->setDate(new \DateTimeImmutable('-2 days'))->setType('Entorse ' . $j->getNom()));
        }
        $educ = $this->compteEncadrement($club, "educ-$id@exemple.test", 'Educ', [Categorie::SECTEURS[$a->getSecteur()]['role'], 'ROLE_INFIRMERIE']);
        $educ->setEcheance('infirmerie', new \DateTimeImmutable('+30 days'));
        $em->flush();

        // L'Infirmerie n'élargit plus la liste des joueurs ni les fiches.
        $this->client->loginUser($educ);
        $this->client->request('GET', '/joueurs');
        self::assertSelectorTextContains('main', 'Dedans');
        self::assertSelectorTextNotContains('main', 'Dehors');
        $this->client->request('GET', '/joueurs/' . $ailleurs->getId());
        self::assertResponseStatusCodeSame(403);

        // Dans l'Infirmerie : ses secteurs seulement.
        $this->ouvrirMedical($this->client);
        $this->client->request('GET', '/infirmerie?statut=toutes');
        self::assertSelectorTextContains('main', 'Entorse Dedans');
        self::assertSelectorTextNotContains('main', 'Entorse Dehors');
        $this->client->request('GET', '/infirmerie/dossiers');
        self::assertSelectorTextNotContains('main', 'Dehors');
        $this->client->request('GET', '/infirmerie/dossiers/' . $ailleurs->getId());
        self::assertResponseStatusCodeSame(403);
        $this->client->request('GET', '/infirmerie/blessures/nouvelle?joueur=' . $ailleurs->getId());
        self::assertResponseStatusCodeSame(403);

        // L'administrateur voit tout le club.
        $this->client->loginUser($admin);
        $this->ouvrirMedical($this->client);
        $this->client->request('GET', '/infirmerie?statut=toutes');
        self::assertSelectorTextContains('main', 'Entorse Dehors');
    }

    public function testNouvellePersonneMedicale(): void
    {
        $id = bin2hex(random_bytes(4));
        $admin = (new Utilisateur())->setEmail("admin-$id@exemple.test")->setNom('Test')->setPrenom('Admin');
        $club = (new Club())->setNom("Club Test $id");
        static::getContainer()->get(CreationClub::class)->creer($club, $admin, 'motdepasse-admin');
        $this->clubId = $club->getId();
        $categorie = $this->em()->getRepository(Categorie::class)->findOneBy(['club' => $club], ['ordre' => 'ASC']);
        $medecin = $this->compteEncadrement($club, "medecin-$id@exemple.test", 'Docteur', ['ROLE_MEDECIN']);
        $coach = $this->compteEncadrement($club, "coach-$id@exemple.test", 'Coach', [Categorie::SECTEURS[$categorie->getSecteur()]['role']]);
        $this->em()->flush();

        // Le médecin crée le compte d'un kiné : Infirmerie (voir) pour 1 semaine.
        $this->client->loginUser($medecin);
        $crawler = $this->client->request('GET', '/utilisateurs/droits');
        $crawler = $this->client->click($crawler->selectLink('+ Nouvelle personne (accès médical)')->link());
        $email = "kine-$id@exemple.test";
        $this->client->submit($crawler->selectButton('Enregistrer')->form(), [
            'form[prenom]' => 'Paul', 'form[nom]' => 'Kiné', 'form[email]' => $email,
            'form[infirmerie]' => '1', 'form[visite_medicale]' => '0', 'form[duree]' => '7',
        ]);
        self::assertResponseRedirects('/utilisateurs/droits');
        self::assertEmailCount(1);
        $message = self::getMailerMessage();
        self::assertInstanceOf(\Symfony\Component\Mime\Email::class, $message);
        self::assertSame(1, preg_match('#https?://\S+/mot-de-passe/[0-9a-f]{48}#', $message->getTextBody(), $m));
        $kine = $this->em()->getRepository(Utilisateur::class)->findOneBy(['email' => $email]);
        self::assertTrue($kine->isMedicalSeul());
        self::assertSame(1, $kine->getNiveau('infirmerie'));
        self::assertSame(0, $kine->getNiveau('visite_medicale'));
        self::assertSame((new \DateTimeImmutable('+7 days'))->format('Y-m-d'), $kine->getEcheance('infirmerie')->format('Y-m-d'));

        // Le kiné choisit son mot de passe : il ne voit que l'Infirmerie.
        $this->client->restart();
        $crawler = $this->client->request('GET', $m[0]);
        $this->client->submit($crawler->selectButton('Enregistrer et me connecter')->form(), [
            'form[motDePasse][first]' => 'secret-kine', 'form[motDePasse][second]' => 'secret-kine',
        ]);
        $this->client->followRedirect();
        self::assertResponseRedirects('/infirmerie');

        // Un éducateur ne peut pas créer de compte médical.
        $this->client->loginUser($this->compte($coach));
        $this->client->request('GET', '/utilisateurs/droits/nouvelle-personne');
        self::assertResponseStatusCodeSame(403);
    }

    /** @param list<string> $roles */
    private function compteEncadrement(Club $club, string $email, string $prenom, array $roles): Utilisateur
    {
        $u = (new Utilisateur())->setClub($club)->setEmail($email)->setNom('Test')->setPrenom($prenom)->setRolesAttribues($roles)->setPassword('inutile');
        $this->em()->persist($u);

        return $u;
    }

    private function compte(Utilisateur $u): Utilisateur
    {
        $em = $this->em();
        $em->clear();

        return $em->getRepository(Utilisateur::class)->find($u->getId());
    }

    private function em(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }
}
