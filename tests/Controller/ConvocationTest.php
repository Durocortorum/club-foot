<?php

namespace App\Tests\Controller;

use App\Entity\Categorie;
use App\Entity\Club;
use App\Entity\Convocation;
use App\Entity\Convoque;
use App\Entity\Joueur;
use App\Entity\Participation;
use App\Entity\Rencontre;
use App\Entity\Utilisateur;
use App\Service\CreationClub;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Convocations : l'éducateur convoque et envoie, la famille répond dans son espace,
 * les présents préremplissent la feuille de match. Données fictives, club supprimé à la fin.
 */
class ConvocationTest extends WebTestCase
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
            foreach (['rencontre', 'joueur', 'club'] as $table) {
                $connexion->executeStatement("DELETE FROM $table WHERE " . ('club' === $table ? 'id' : 'club_id') . ' = ?', [$this->clubId]);
            }
        }
        parent::tearDown();
    }

    /** Joueurs proposés : la catégorie du match, le niveau du dessous et celui du dessus (même d'un autre secteur). */
    public function testJoueursDesNiveauxVoisins(): void
    {
        $id = bin2hex(random_bytes(4));
        $admin = (new Utilisateur())->setEmail("admin-$id@exemple.test")->setNom('Test')->setPrenom('Coach');
        $club = (new Club())->setNom("Club Test $id");
        static::getContainer()->get(CreationClub::class)->creer($club, $admin, 'motdepasse-admin');
        $this->clubId = $club->getId();
        $em = $this->em();
        $cat = [];
        foreach ($em->getRepository(Categorie::class)->findBy(['club' => $club], ['ordre' => 'ASC']) as $c) {
            $cat[$c->getNom()] = $c;
        }
        // U7, U9, U11 (école de foot), U13 (préformation) : match des U11.
        foreach (['U7' => 'Ugo', 'U9' => 'Paul', 'U11' => 'Léa', 'U13' => 'Zoé', 'U15' => 'Max'] as $nom => $prenom) {
            $this->joueur($club, $cat[$nom], $prenom);
        }
        $rencontre = (new Rencontre())->setClub($club)->setCategorie($cat['U11'])->setDate(new \DateTimeImmutable('+4 days'))
            ->setDomicile("CLUB TEST $id")->setExterieur('Adversaire FC');
        $em->persist($rencontre);
        $em->flush();

        $this->client->loginUser($admin);
        $crawler = $this->client->request('GET', '/matchs/' . $rencontre->getId() . '/convocation');
        self::assertResponseIsSuccessful();
        $groupes = $crawler->filter('details.groupe-joueurs summary')->each(fn ($s) => trim(preg_replace('/\s*\(.*$/', '', $s->text())));
        self::assertSame(['U11', 'U13 · niveau du dessus', 'U9 · 1 niveau en dessous', 'Autres catégories'], $groupes);
        self::assertSelectorTextContains('details.groupe-joueurs:nth-of-type(2)', 'Zoé');
        self::assertSelectorTextContains('details.groupe-joueurs:nth-of-type(4)', 'Ugo');
        self::assertSelectorTextNotContains('form', 'Max'); // U15 : ni voisin, ni du secteur

        // Option « tous les niveaux en dessous » : U9 puis U7, chacun dans son groupe.
        $tous = $this->client->click($crawler->filter('.niveaux-dessous')->selectLink('tous')->link());
        $groupesTous = $tous->filter('details.groupe-joueurs summary')->each(fn ($s) => trim(preg_replace('/\s*\(.*$/', '', $s->text())));
        self::assertSame(['U11', 'U13 · niveau du dessus', 'U9 · 1 niveau en dessous', 'U7 · 2 niveaux en dessous'], $groupesTous);
        self::assertStringEndsWith('#joueurs-convoques', $tous->filter('.niveaux-dessous a')->first()->attr('href'));
        self::assertSelectorNotExists('#joueurs-convoques p.discret');

        // Zoé (U13) convoquée : son groupe s'ouvre et le compte apparaît.
        $form = $crawler->selectButton('Enregistrer')->form();
        foreach ($form['form[joueurs]'] as $case) {
            $label = $crawler->filter('input[value="' . $case->availableOptionValues()[0] . '"]')->closest('label')->text();
            if (str_contains($label, 'Léa') || str_contains($label, 'Zoé')) {
                $case->tick();
            }
        }
        $this->client->submit($form);
        $crawler = $this->client->followRedirect();
        self::assertSelectorTextContains('details.groupe-joueurs[open]:nth-of-type(2) summary', '1 convoqué');
    }

    /** Un joueur convoqué le dimanche avec les U13 ne peut pas l'être le samedi avec les U11 (même week-end). */
    public function testPasDeuxConvocationsLeMemeWeekEnd(): void
    {
        $id = bin2hex(random_bytes(4));
        $admin = (new Utilisateur())->setEmail("admin-$id@exemple.test")->setNom('Test')->setPrenom('Coach');
        $club = (new Club())->setNom("Club Test $id");
        static::getContainer()->get(CreationClub::class)->creer($club, $admin, 'motdepasse-admin');
        $this->clubId = $club->getId();
        $em = $this->em();
        $cat = [];
        foreach ($em->getRepository(Categorie::class)->findBy(['club' => $club], ['ordre' => 'ASC']) as $c) {
            $cat[$c->getNom()] = $c;
        }
        $zoe = $this->joueur($club, $cat['U13'], 'Zoé');
        $this->joueur($club, $cat['U11'], 'Léa');
        $samedi = new \DateTimeImmutable('saturday next week');
        $match = fn (Categorie $c, \DateTimeImmutable $d) => (new Rencontre())->setClub($club)->setCategorie($c)->setDate($d)->setDomicile("CLUB TEST $id")->setExterieur('Adversaire FC');
        $em->persist($u11Samedi = $match($cat['U11'], $samedi));
        $em->persist($u13Dimanche = $match($cat['U13'], $samedi->modify('+1 day')));
        $em->persist($u11Mercredi = $match($cat['U11'], $samedi->modify('+4 days')));
        $convocation = (new Convocation($u13Dimanche))->setClub($club)->setJoueurs([$zoe]);
        foreach ($convocation->getConvoques() as $cq) {
            $cq->setClub($club);
        }
        $em->persist($convocation);
        $em->flush();

        // Samedi (U11) : Zoé (U13, niveau du dessus) est grisée, avec le match du dimanche.
        $this->client->loginUser($admin);
        $crawler = $this->client->request('GET', '/matchs/' . $u11Samedi->getId() . '/convocation');
        self::assertSelectorExists('input[value="' . $zoe->getId() . '"][disabled]');
        self::assertSelectorTextContains('label.indisponible', 'avec ' . $u13Dimanche->getEquipeAffichee() . ' ' . $u13Dimanche->getDate()->format('d/m'));
        // Forcée malgré tout : refusée.
        $jeton = $crawler->filter('input[name="form[_token]"]')->attr('value');
        $this->client->request('POST', '/matchs/' . $u11Samedi->getId() . '/convocation', ['form' => ['joueurs' => [(string) $zoe->getId()], '_token' => $jeton]]);
        self::assertResponseStatusCodeSame(422);
        self::assertNull(static::getContainer()->get(\App\Repository\ConvocationRepository::class)->pour($u11Samedi));

        // Mercredi suivant : pas le même week-end, Zoé est libre.
        $this->client->request('GET', '/matchs/' . $u11Mercredi->getId() . '/convocation');
        self::assertSelectorNotExists('input[value="' . $zoe->getId() . '"][disabled]');
    }

    public function testConvocationJusquALaFeuilleDeMatch(): void
    {
        $id = bin2hex(random_bytes(4));
        $admin = (new Utilisateur())->setEmail("admin-$id@exemple.test")->setNom('Test')->setPrenom('Coach');
        $club = (new Club())->setNom("Club Test $id");
        static::getContainer()->get(CreationClub::class)->creer($club, $admin, 'motdepasse-admin');
        $this->clubId = $club->getId();

        $em = $this->em();
        $categorie = $em->getRepository(Categorie::class)->findOneBy(['club' => $club], ['ordre' => 'ASC']);
        $lea = $this->joueur($club, $categorie, 'Léa');
        $tom = $this->joueur($club, $categorie, 'Tom')->setTelPortable1('06 12 34 56 78');
        $this->joueur($club, $categorie, 'Max');
        $rencontre = (new Rencontre())->setClub($club)->setCategorie($categorie)->setDate(new \DateTimeImmutable('+4 days'))
            ->setHeure('15h00')->setDomicile("CLUB TEST $id")->setExterieur('Adversaire FC')->setLieu('Stade municipal');
        $em->persist($rencontre);
        $parent = (new Utilisateur())->setClub($club)->setFamille(true)->setEmail("parent-$id@exemple.test")->setNom('Martin')->setPrenom('Famille')->addJoueur($lea);
        $parent->setPassword(static::getContainer()->get(UserPasswordHasherInterface::class)->hashPassword($parent, 'secret-famille'));
        $em->persist($parent);
        $em->flush();
        $match = '/matchs/' . $rencontre->getId();

        // --- L'éducateur prépare la convocation (Léa et Tom, pas Max).
        $this->client->loginUser($admin);
        $crawler = $this->client->request('GET', $match);
        self::assertSelectorExists('a[href="' . $match . '/convocation"]');
        $crawler = $this->client->request('GET', "$match/convocation");
        self::assertResponseIsSuccessful();
        self::assertInputValueSame('form[rdvLieu]', 'Stade municipal');
        $form = $crawler->selectButton('Enregistrer la convocation')->form();
        $form['form[joueurs]'][0]->tick(); // ordre alphabétique des prénoms à nom égal : Léa, Max, Tom
        $form['form[joueurs]'][2]->tick();
        $form['form[rdvHeure]'] = '14h15';
        $this->client->submit($form);
        $this->client->followRedirect();
        self::assertSelectorTextContains('main', 'pas encore envoyée');

        $convocation = $this->convocation($rencontre);
        self::assertEqualsCanonicalizing(['Léa', 'Tom'], array_map(fn (Joueur $j) => $j->getPrenom(), $convocation->getJoueurs()));

        // Pas encore envoyée : la famille ne la voit pas.
        $this->client->loginUser($parent);
        $this->client->request('GET', '/espace');
        self::assertSelectorNotExists('.convocation-famille');

        // --- Envoi : un e-mail pour la famille de Léa ; Tom n'a pas de compte, il est signalé.
        $this->client->loginUser($admin);
        $crawler = $this->client->request('GET', "$match/convocation");
        $this->client->submit($crawler->selectButton('Envoyer aux familles')->form());
        self::assertEmailCount(1);
        $email = self::getMailerMessage();
        self::assertEmailAddressContains($email, 'to', "parent-$id@exemple.test");
        self::assertEmailTextBodyContains($email, 'Rendez-vous à 14h15, Stade municipal');
        $this->client->followRedirect();
        self::assertSelectorTextContains('.flash-info', 'Tom Martin');
        self::assertSelectorTextContains('[data-copier-texte-value]', 'Joueurs convoqués');
        // SMS depuis le téléphone de l'éducateur : Tom (pas de compte) par son portable parent 1, Léa a un compte.
        $sms = $this->client->getCrawler()->filter('#reponses a[href^="sms:"]');
        self::assertCount(1, $sms);
        self::assertStringStartsWith('sms:0612345678?&body=', $sms->attr('href'));
        self::assertStringContainsString('répondre par SMS', urldecode($sms->attr('href')));
        self::assertSelectorTextContains('#reponses', 'compte famille');

        // La convocation est aussi déposée dans la messagerie interne (un seul e-mail : celui de la convocation).
        $this->em()->clear();
        $message = $this->em()->getRepository(\App\Entity\Message::class)->findOneBy(['rencontre' => $rencontre->getId()]);
        self::assertNotNull($message);
        self::assertSame('convocation', $message->getCible());
        self::assertStringStartsWith('Convocation ', $message->getSujet());
        self::assertStringContainsString('Rendez-vous : 14h15, Stade municipal.', $message->getCorps());
        self::assertCount(2, $message->getJoueurs()); // Léa et Tom
        $this->client->request('GET', '/messages/' . $message->getId());
        self::assertResponseIsSuccessful();

        // --- La famille voit la convocation dans ses messages, avec le bouton pour répondre.
        $this->client->loginUser($parent);
        $this->client->request('GET', '/espace/messages');
        self::assertSelectorTextContains('.boite-reception .non-lu', 'Convocation');
        $this->client->request('GET', '/espace/messages/' . $message->getId());
        self::assertSelectorTextContains('main', 'pour Léa');
        self::assertSelectorExists('a[href="/espace"]:contains("Répondre présent ou absent")');

        // --- La famille répond « absent » (malade), puis change d'avis.
        $crawler = $this->client->request('GET', '/espace');
        self::assertSelectorTextContains('.convocation-famille', 'Rendez-vous à 14h15');
        self::assertSelectorTextContains('.convocation-famille', 'réponse attendue');
        $this->client->submit($crawler->selectButton('Absent')->form(), ['motif' => 'Malade']);
        $crawler = $this->client->followRedirect();
        self::assertSelectorTextContains('.flash-succes', 'Léa sera absent(e)');
        $reponseLea = $this->convoque($rencontre, 'Léa');
        self::assertSame([Convoque::ABSENT, 'Malade', "parent-$id@exemple.test"], [$reponseLea->getReponse(), $reponseLea->getMotif(), $reponseLea->getReponduPar()]);
        $this->client->submit($crawler->selectButton('Présent')->form());
        self::assertSame(Convoque::PRESENT, $this->convoque($rencontre, 'Léa')->getReponse());

        // Elle ne peut ni répondre pour Tom, ni ouvrir la page de l'éducateur.
        $tomConvoque = $this->convoque($rencontre, 'Tom');
        $this->client->request('POST', '/espace/convocation/' . $tomConvoque->getId(), ['reponse' => 'present', '_token' => 'x']);
        self::assertResponseStatusCodeSame(403);
        $this->client->request('GET', "$match/convocation");
        self::assertResponseStatusCodeSame(403);

        // --- L'éducateur note Tom présent (réponse reçue par téléphone) ; relance : plus personne à relancer.
        $this->client->loginUser($admin);
        $crawler = $this->client->request('GET', "$match/convocation");
        $ligneTom = $crawler->filter('#reponses tr')->reduce(fn ($tr) => str_contains($tr->text(), 'Tom'));
        $this->client->submit($ligneTom->filter('form.reponse-convoque')->form(), ['reponse' => 'present']);
        self::assertSame([Convoque::PRESENT, 'Coach Test'], [$this->convoque($rencontre, 'Tom')->getReponse(), $this->convoque($rencontre, 'Tom')->getReponduPar()]);
        $crawler = $this->client->request('GET', "$match/convocation");
        self::assertSelectorTextContains('#reponses', '2 présents');
        self::assertSelectorNotExists('input[name="relance"]');

        // --- La feuille de match vide est préremplie avec les deux présents.
        $crawler = $this->client->request('GET', "$match/modifier");
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.flash-info', 'préremplie avec les 2 joueurs');
        $choisis = $crawler->filter('select[name$="[joueur]"] option[selected]')->each(fn ($o) => $o->text());
        self::assertCount(2, $choisis);

        // --- Convocation supprimée (erreur) : la ligne vierge de Tom quitte la feuille, celle de Léa (n° saisi) reste.
        $em = $this->em();
        $em->clear();
        $r = $em->find(Rencontre::class, $rencontre->getId());
        $r->addParticipation((new Participation())->setClub($r->getClub())->setJoueur($em->find(Joueur::class, $tom->getId())));
        $r->addParticipation((new Participation())->setClub($r->getClub())->setJoueur($em->find(Joueur::class, $lea->getId()))->setNumero(9));
        $em->flush();
        $crawler = $this->client->request('GET', "$match/convocation");
        $this->client->submit($crawler->filter('form[action$="/convocation/supprimer"]')->form());
        $this->client->followRedirect();
        self::assertSelectorTextContains('.flash-succes', '1 joueur retiré de la feuille de match');
        $em->clear();
        $restants = array_map(fn (Participation $p) => $p->getJoueur()->getPrenom(), $em->find(Rencontre::class, $rencontre->getId())->getParticipations()->toArray());
        self::assertSame(['Léa'], $restants);
        self::assertNull($em->getRepository(Convocation::class)->findOneBy(['rencontre' => $rencontre->getId()]));
    }

    /** Nouveau match : seulement le bloc « Match » (stade compris), puis directement la convocation. */
    public function testNouveauMatchPuisConvocation(): void
    {
        $id = bin2hex(random_bytes(4));
        $admin = (new Utilisateur())->setEmail("admin-$id@exemple.test")->setNom('Test')->setPrenom('Coach');
        $club = (new Club())->setNom("Club Test $id");
        static::getContainer()->get(CreationClub::class)->creer($club, $admin, 'motdepasse-admin');
        $this->clubId = $club->getId();
        $categorie = $this->em()->getRepository(Categorie::class)->findOneBy(['club' => $club], ['ordre' => 'ASC']);

        $this->client->loginUser($admin);
        $crawler = $this->client->request('GET', '/matchs/nouveau');
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('fieldset:first-of-type [name="rencontre[lieu]"]');
        self::assertSelectorExists('div[hidden] [name="rencontre[terrain]"]');

        $form = $crawler->selectButton('Enregistrer et convoquer')->form([
            'rencontre[date]' => (new \DateTimeImmutable('+5 days'))->format('Y-m-d'),
            'rencontre[heure]' => '14h30',
            'rencontre[categorie]' => (string) $categorie->getId(),
            'rencontre[adversaire]' => 'SEDAN',
            'rencontre[lieu]' => 'Stade du château',
        ]);
        $this->client->submit($form);
        $rencontre = $this->em()->getRepository(Rencontre::class)->findOneBy(['club' => $club]);
        self::assertResponseRedirects('/matchs/' . $rencontre->getId() . '/convocation');
        $this->client->followRedirect();
        self::assertInputValueSame('form[rdvLieu]', 'Stade du château');

        // Pas encore joué : pas de score, le match ne compte pas dans le bilan.
        self::assertNull($rencontre->getButsDomicile());
        $this->client->request('GET', '/matchs');
        self::assertSelectorNotExists('.tuile'); // bilan masqué : aucun match joué

        // Modifié sans cocher « Match joué » (score 0 – 0 envoyé par la page) : toujours pas de score.
        $crawler = $this->client->request('GET', '/matchs/' . $rencontre->getId() . '/modifier');
        self::assertCheckboxNotChecked('rencontre[joue]');
        $form = $crawler->selectButton('Enregistrer le match')->form();
        $form['rencontre[butsDomicile]'] = '0';
        $form['rencontre[butsExterieur]'] = '0';
        $this->client->submit($form);
        $this->em()->clear();
        self::assertNull($this->em()->find(Rencontre::class, $rencontre->getId())->getButsDomicile());

        // Case cochée : 0 – 0, un nul.
        $form = $this->client->request('GET', '/matchs/' . $rencontre->getId() . '/modifier')->selectButton('Enregistrer le match')->form();
        $form['rencontre[joue]']->tick();
        $form['rencontre[butsDomicile]'] = '0';
        $form['rencontre[butsExterieur]'] = '0';
        $this->client->submit($form);
        $this->em()->clear();
        self::assertSame('N', $this->em()->find(Rencontre::class, $rencontre->getId())->getResultat());
    }

    private function joueur(Club $club, Categorie $categorie, string $prenom): Joueur
    {
        $joueur = (new Joueur())->setClub($club)->setCategorie($categorie)->setNom('Martin')->setPrenom($prenom);
        $this->em()->persist($joueur);

        return $joueur;
    }

    private function convocation(Rencontre $rencontre): Convocation
    {
        $em = $this->em();
        $em->clear();

        return $em->getRepository(Convocation::class)->findOneBy(['rencontre' => $rencontre->getId()]);
    }

    private function convoque(Rencontre $rencontre, string $prenom): Convoque
    {
        foreach ($this->convocation($rencontre)->getConvoques() as $c) {
            if ($c->getJoueur()->getPrenom() === $prenom) {
                return $c;
            }
        }
        self::fail("$prenom n'est pas convoqué.");
    }

    private function em(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }
}
