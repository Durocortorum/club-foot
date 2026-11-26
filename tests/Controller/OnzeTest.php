<?php

namespace App\Tests\Controller;

use App\Entity\Blessure;
use App\Entity\Categorie;
use App\Entity\Club;
use App\Entity\Convocation;
use App\Entity\Joueur;
use App\Entity\Participation;
use App\Entity\Presence;
use App\Entity\Rencontre;
use App\Entity\Sanction;
use App\Entity\SemainePresence;
use App\Entity\Utilisateur;
use App\Service\CreationClub;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * « Composer le onze » : notes par poste de la saison, joueur blessé et joueur suspendu signalés,
 * onze validé reporté sur la feuille de match. Données fictives, club supprimé à la fin.
 */
class OnzeTest extends WebTestCase
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
            foreach (['semaine_presence', 'rencontre', 'blessure', 'sanction', 'joueur', 'club'] as $table) {
                $connexion->executeStatement("DELETE FROM $table WHERE " . ('club' === $table ? 'id' : 'club_id') . ' = ?', [$this->clubId]);
            }
        }
        parent::tearDown();
    }

    public function testComposerEtValiderLeOnze(): void
    {
        $id = bin2hex(random_bytes(4));
        $admin = (new Utilisateur())->setEmail("admin-$id@exemple.test")->setNom('Test')->setPrenom('Coach');
        $club = (new Club())->setNom("Club Test $id");
        static::getContainer()->get(CreationClub::class)->creer($club, $admin, 'motdepasse-admin');
        $this->clubId = $club->getId();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $u17 = $em->getRepository(Categorie::class)->findOneBy(['club' => $club, 'nom' => 'U17']);
        $nous = "CLUB TEST $id";

        $joueurs = [];
        foreach (['Gaby', 'Léo', 'Tom', 'Max', 'Noé', 'Sam'] as $prenom) {
            $joueurs[$prenom] = (new Joueur())->setClub($club)->setCategorie($u17)->setNom('Martin')->setPrenom($prenom);
            $em->persist($joueurs[$prenom]);
        }
        // Match joué il y a une semaine : Léo noté 8 en latéral droit, Tom expulsé (carton rouge).
        $passe = (new Rencontre())->setClub($club)->setCategorie($u17)->setSysteme('4 4 2')->setDate(new \DateTimeImmutable('-7 days'))
            ->setDomicile($nous)->setExterieur('FC Les Moulins')->setButsDomicile(2)->setButsExterieur(1);
        $passe->addParticipation((new Participation())->setClub($club)->setJoueur($joueurs['Léo'])->setNumero(2)->setTitulaire(true)->setPoste('Latéral droit')->setTempsJeu(90)->setNote(8.0));
        $passe->addParticipation((new Participation())->setClub($club)->setJoueur($joueurs['Tom'])->setNumero(5)->setTitulaire(true)->setPoste('Défenseur central')->setTempsJeu(60)->setCarton('RJ')->setMinuteCarton('60'));
        $em->persist($passe);
        // Max blessé sans date de reprise ; Noé suspendu deux matchs il y a trois jours.
        $em->persist((new Blessure())->setClub($club)->setJoueur($joueurs['Max'])->setDate(new \DateTimeImmutable('-2 days'))->setType('Entorse'));
        $em->persist((new Sanction())->setClub($club)->setJoueur($joueurs['Noé'])->setDate(new \DateTimeImmutable('-3 days'))->setMotif('Insulte')->setSanctionSportive('2 matchs de suspension'));
        $match = (new Rencontre())->setClub($club)->setCategorie($u17)->setCompetition('Championnat')->setDate(new \DateTimeImmutable('+3 days'))
            ->setDomicile($nous)->setExterieur('US Vallée Verte');
        // Sam est déjà sur la feuille (préremplie) mais ne sera pas retenu : il la quitte.
        $match->addParticipation((new Participation())->setClub($club)->setJoueur($joueurs['Sam'])->setNumero(14)->setTitulaire(false));
        $em->persist($match);
        $em->flush();

        $this->client->loginUser($admin);
        $crawler = $this->client->request('GET', '/matchs/' . $match->getId());
        $crawler = $this->client->click($crawler->selectLink('👕 Composer le onze')->link());
        self::assertResponseIsSuccessful();
        $config = json_decode($crawler->filter('[data-controller="onze"]')->attr('data-onze-config-value'), true);
        $parNom = array_column($config['donnees']['joueurs'], null, 'prenom');
        self::assertSame(8.0, (float) $parNom['Léo']['notesPostes']['Latéral droit']['note']);
        self::assertSame('blesse', $parNom['Max']['etat']);
        // L'administrateur a le droit Infirmerie : il voit le motif.
        self::assertStringContainsString('entorse', $parNom['Max']['libelleEtat']);
        self::assertSame('suspendu', $parNom['Noé']['etat']);
        self::assertStringContainsString('encore 2 matchs', $parNom['Noé']['libelleEtat']);
        self::assertSame('suspendu', $parNom['Tom']['etat']);
        self::assertSame('dispo', $parNom['Gaby']['etat']);
        // Formation 4 4 2 : 11 places, le gardien et les latéraux nommés.
        $places = $config['donnees']['formations']['4 4 2'];
        self::assertCount(11, $places);
        self::assertSame('Gardien', $places[0]['poste']);
        self::assertContains('Latéral droit', array_column($places, 'poste'));

        // Validation : Gaby au but, Léo latéral droit, Tom sur le banc.
        $ld = array_values(array_filter($places, fn ($p) => 'Latéral droit' === $p['poste']))[0];
        $this->client->request('POST', '/matchs/' . $match->getId() . '/onze', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            '_token' => $config['jeton'],
            'systeme' => '4 4 2',
            'titulaires' => [
                ['joueur' => $joueurs['Gaby']->getId(), 'x' => $places[0]['x'], 'y' => $places[0]['y'], 'poste' => 'Gardien'],
                ['joueur' => $joueurs['Léo']->getId(), 'x' => $ld['x'], 'y' => $ld['y'], 'poste' => 'Latéral droit'],
            ],
            'remplacants' => [$joueurs['Tom']->getId()],
        ]));
        self::assertResponseIsSuccessful();
        self::assertSame('/matchs/' . $match->getId(), json_decode($this->client->getResponse()->getContent(), true)['retour']);

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $match = $em->find(Rencontre::class, $match->getId());
        self::assertSame('4 4 2', $match->getSysteme());
        $feuille = [];
        foreach ($match->getParticipations() as $p) {
            $feuille[$p->getJoueur()->getPrenom()] = [$p->isTitulaire(), $p->getPoste()];
        }
        self::assertSame(['Gaby' => [true, 'Gardien'], 'Léo' => [true, 'Latéral droit'], 'Tom' => [false, null]], $feuille);
        self::assertEquals([$ld['x'], $ld['y']], $match->getPlacements()[$joueurs['Léo']->getId()]);

        // Convocation envoyée à Gaby, Léo et Noé : toute l'équipe reste visible (pour préparer), mais seuls eux
        // sont marqués convoqués (vue « Convoqués », et seuls retenus par « Meilleur onze »).
        $club = $em->find(Club::class, $this->clubId);
        $convocation = (new Convocation($match))->setClub($club)->setJoueurs(array_map(fn (string $p) => $em->find(Joueur::class, $joueurs[$p]->getId()), ['Gaby', 'Léo', 'Noé']))->marquerEnvoyee();
        foreach ($convocation->getConvoques() as $cq) {
            $cq->setClub($club);
        }
        $em->persist($convocation);
        $em->flush();
        $crawler = $this->client->request('GET', '/matchs/' . $match->getId() . '/onze');
        $config = json_decode($crawler->filter('[data-controller="onze"]')->attr('data-onze-config-value'), true);
        self::assertCount(6, $config['donnees']['joueurs']);
        $convoques = array_column(array_filter($config['donnees']['joueurs'], fn ($j) => $j['convoque']), 'prenom');
        sort($convoques);
        self::assertSame(['Gaby', 'Léo', 'Noé'], $convoques);
        self::assertTrue($config['donnees']['avecConvocation']);

        // Semaine d'entraînement notée dans la grille des présences : note et forme reprises dans « Composer le onze ».
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $club = $em->find(Club::class, $this->clubId);
        $semaine = (new SemainePresence())->setClub($club)->setLundi(new \DateTimeImmutable('monday this week'))->setCategorie($em->find(Categorie::class, $u17->getId()));
        $semaine->addPresence((new Presence())->setClub($club)->setJoueur($em->find(Joueur::class, $joueurs['Gaby']->getId())));
        $em->persist($semaine);
        $em->flush();
        $crawler = $this->client->request('GET', '/presences/' . $semaine->getId());
        $form = $crawler->selectButton('Enregistrer les présences')->form();
        $form['note[' . $joueurs['Gaby']->getId() . ']'] = '7,5';
        // Plus de choix de la forme : elle découle de la note (7,5 → « En grande forme »).
        self::assertCount(0, $crawler->filter('select[name^="forme["]'));
        // Sans planning ni séance notée : toutes les demi-journées sont proposées.
        self::assertCount(0, $crawler->filter('.grille-presence th.creneau-cache'));
        $this->client->submit($form);
        self::assertResponseRedirects();
        $crawler = $this->client->request('GET', '/matchs/' . $match->getId() . '/onze');
        $config = json_decode($crawler->filter('[data-controller="onze"]')->attr('data-onze-config-value'), true);
        $gaby = array_column($config['donnees']['joueurs'], null, 'prenom')['Gaby'];
        self::assertSame(['top', 7.5], [$gaby['semaine']['forme'], (float) $gaby['semaine']['note']]);
        $this->client->request('GET', '/matchs/' . $match->getId() . '/convocation');
        self::assertSelectorTextContains('.forme-sem', '🔥 7,5');

        // Mauvais jeton : refusé.
        $this->client->request('POST', '/matchs/' . $match->getId() . '/onze', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode(['_token' => 'x', 'systeme' => '4 4 2']));
        self::assertResponseStatusCodeSame(400);
    }
}
