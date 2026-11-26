<?php

namespace App\Tests\Controller;

use App\Entity\Categorie;
use App\Entity\Club;
use App\Entity\FicheAdversaire;
use App\Entity\Rencontre;
use App\Entity\Utilisateur;
use App\Service\CreationClub;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Analyse de l'adversaire : confrontations passées reconnues malgré un nom écrit autrement, match retour,
 * notes de l'éducateur, organisation de l'adversaire et vidéo du dernier match. Données fictives, club supprimé à la fin.
 */
class AdversaireTest extends WebTestCase
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
            $connexion->executeStatement('DELETE FROM fiche_adversaire WHERE club_id = ?', [$this->clubId]);
            $connexion->executeStatement('DELETE FROM rencontre WHERE club_id = ?', [$this->clubId]);
            $connexion->executeStatement('DELETE FROM club WHERE id = ?', [$this->clubId]);
        }
        parent::tearDown();
    }

    public function testFicheAdversaire(): void
    {
        $id = bin2hex(random_bytes(4));
        $admin = (new Utilisateur())->setEmail("admin-$id@exemple.test")->setNom('Test')->setPrenom('Coach');
        $club = (new Club())->setNom("Club Test $id");
        static::getContainer()->get(CreationClub::class)->creer($club, $admin, 'motdepasse-admin');
        $this->clubId = $club->getId();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $u15 = $em->getRepository(Categorie::class)->findOneBy(['club' => $club, 'nom' => 'U15']);
        $nous = mb_strtoupper("Club Test $id");
        Rencontre::reconnaitreClub($nous);

        // Aller gagné 3-1 à domicile contre « US VALLÉE VERTE », retour à venir chez « VALLÉE VERTE ».
        $aller = (new Rencontre())->setClub($club)->setCategorie($u15)->setCompetition('Championnat')
            ->setDate(new \DateTimeImmutable('-20 days'))->setDomicile($nous)->setExterieur('US Vallée Verte')
            ->setButsDomicile(3)->setButsExterieur(1);
        $retour = (new Rencontre())->setClub($club)->setCategorie($u15)->setCompetition('Championnat')
            ->setDate(new \DateTimeImmutable('+20 days'))->setDomicile('Vallée Verte')->setExterieur($nous);
        $autre = (new Rencontre())->setClub($club)->setCategorie($u15)->setCompetition('Championnat')
            ->setDate(new \DateTimeImmutable('-10 days'))->setDomicile($nous)->setExterieur('FC Les Moulins')
            ->setButsDomicile(0)->setButsExterieur(2);
        foreach ([$aller, $retour, $autre] as $r) {
            $em->persist($r);
        }
        $em->flush();
        // L'aller et le retour ont lieu la même saison, sinon le libellé « Match retour » n'a pas de sens.
        if ($aller->getSaison() !== $retour->getSaison()) {
            $this->markTestSkipped('Aller et retour à cheval sur deux saisons à cette date.');
        }

        $this->client->loginUser($admin);
        $crawler = $this->client->request('GET', '/matchs/' . $retour->getId());
        $crawler = $this->client->click($crawler->selectLink('🔎 Adversaire')->link());
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'VALLÉE VERTE');
        self::assertSelectorTextContains('.tuile-prochain', 'Match retour');
        // Un seul match joué contre lui (pas celui contre FC Les Moulins), gagné 3-1.
        self::assertCount(1, $crawler->filter('#historique table tbody tr'));
        self::assertSelectorTextContains('#historique .tuile .resultat-V', '1');
        self::assertSelectorTextContains('#historique', '3 – 1');

        $form = $crawler->selectButton('Enregistrer')->form([
            'points_forts' => 'Pressing haut',
            'points_faibles' => 'Espace dans leur dos',
            'a_retenir' => 'Démarrent fort',
            'preparation' => 'Jouer en 4 2 3 1',
            'systeme_adverse' => '4 3 3',
            'lien_video' => 'app.veo.co/matches/test',
            'systeme_habituel' => '4 2 3 1',
            'jeu_pressing' => 'Pressing haut les 20 premières minutes',
            'niveau_pressing' => '4',
            'jeu_cpa' => 'Dangereux sur corners',
            'niveau_cpa' => '5',
            'joueurs[0][numero]' => '10',
            'joueurs[0][poste]' => 'Meneur de jeu',
            'joueurs[0][note]' => 'Créatif, bon tireur',
            'joueurs[1][numero]' => '',
            'effectif[0][numero]' => '10',
            'effectif[0][poste]' => 'MIL',
            'effectif[1][numero]' => '1',
            'effectif[1][nom]' => 'Garnier',
            'effectif[1][poste]' => 'G',
            'effectif[2][nom]' => 'Inconnu',
            'videos[0][titre]' => 'Leurs corners',
            'videos[0][url]' => 'youtube.com/watch?v=test',
        ]);
        $this->client->submit($form);
        self::assertResponseRedirects('/matchs/' . $retour->getId() . '/adversaire');
        $crawler = $this->client->followRedirect();
        self::assertSelectorTextContains('#notes', 'Pressing haut');
        self::assertSelectorTextContains('#preparation', 'Jouer en 4 2 3 1');
        // Onglets : analyse (système dessiné, animation de jeu notée), effectif, vidéo.
        self::assertCount(4, $crawler->filter('.adv-onglets [data-onglet]'));
        self::assertSelectorTextContains('.adv-systeme-nom', '4-2-3-1');
        self::assertCount(11, $crawler->filter('.adv-mini-terrain .adv-pion'), 'gardien et 10 joueurs de champ');
        self::assertCount(4, $crawler->filter('.adv-theme')->first()->filter('.adv-niveau i.plein'), 'pressing : danger 4/5');
        self::assertSelectorTextContains('#onglet-effectif', 'N°10 – Meneur de jeu');
        // Effectif par ligne : gardiens d'abord, nom connu ou numéro, étoile pour le joueur dangereux, sans poste à la fin.
        self::assertSame(['Gardiens (1)', 'Milieux (1)', 'Sans poste (1)'], $crawler->filter('.adv-effectif-ligne')->each(fn ($h) => $h->text()));
        self::assertSelectorTextContains('.adv-maillots', 'Garnier');
        self::assertCount(1, $crawler->filter('.adv-maillots li.danger'), 'le N°10 est aussi un joueur dangereux');
        self::assertSelectorTextContains('#onglet-video', 'Leurs corners');
        self::assertSelectorTextContains('#onglet-video', 'Dernier match');

        $em->clear();
        $aller = $em->find(Rencontre::class, $aller->getId());
        self::assertSame('4 3 3', $aller->getSystemeAdverse());
        self::assertSame('https://app.veo.co/matches/test', $aller->getLienVideo());
        $fiche = $em->getRepository(FicheAdversaire::class)->findOneBy(['club' => $club]);
        self::assertSame('vallee verte', $fiche->getCle());
        self::assertSame('Coach TEST', $fiche->getModifieParNom());
        self::assertSame('4 2 3 1', $fiche->getSystemeHabituel());
        self::assertSame(['texte' => 'Dangereux sur corners', 'niveau' => 5], $fiche->getTheme('cpa'));
        self::assertSame(5, (clone $fiche)->setTheme('cpa', 'x', 9)->getTheme('cpa')['niveau'], 'niveau ramené à 5 au plus');
        self::assertSame(['texte' => null, 'niveau' => 0], $fiche->getTheme('relance'));
        self::assertEquals([['numero' => 10, 'poste' => 'Meneur de jeu', 'note' => 'Créatif, bon tireur']], $fiche->getJoueursDangereux(), 'ligne vide écartée');
        self::assertEquals([['titre' => 'Leurs corners', 'url' => 'https://youtube.com/watch?v=test']], $fiche->getVideos());

        // Juste après un match sifflé dans le Match Live, score pas encore remonté : c'est la dernière confrontation,
        // et le bouton « Analyser l'adversaire » ouvre le formulaire.
        $club = $em->find(Club::class, $this->clubId);
        $u15 = $em->find(Categorie::class, $u15->getId());
        $direct = (new Rencontre())->setClub($club)->setCategorie($u15)->setCompetition('Amical')
            ->setDate(new \DateTimeImmutable('today'))->setDomicile($nous)->setExterieur('AS Les Collines');
        $direct->majChronoDirect(['periode' => 2, 'enCours' => false, 'cumul' => 5400, 'fini' => true, 'maj' => 1]);
        $em->persist($direct);
        $em->flush();
        $crawler = $this->client->request('GET', '/matchs/' . $direct->getId() . '/live');
        self::assertResponseIsSuccessful();
        $this->client->click($crawler->selectLink('🔎 Analyser l\'adversaire')->link());
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('#derniere');
        self::assertSelectorExists('details#modifier[open]');
        self::assertSelectorExists('#lien_video');
    }
}
