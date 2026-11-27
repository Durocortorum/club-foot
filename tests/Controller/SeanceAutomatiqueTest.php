<?php

namespace App\Tests\Controller;

use App\Entity\Categorie;
use App\Entity\Club;
use App\Entity\Seance;
use App\Entity\Utilisateur;
use App\Service\CreationClub;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Séance automatique : formulaire (équipe, date, durée, thèmes), séance composée avec la bibliothèque et enregistrée.
 * Club créé pour le test, supprimé à la fin.
 */
class SeanceAutomatiqueTest extends WebTestCase
{
    private ?int $clubId = null;

    protected function tearDown(): void
    {
        if ($this->clubId) {
            $connexion = static::getContainer()->get(EntityManagerInterface::class)->getConnection();
            $connexion->executeStatement('DELETE FROM seance WHERE categorie_id IN (SELECT id FROM categorie WHERE club_id = ?)', [$this->clubId]);
            $connexion->executeStatement('DELETE FROM club WHERE id = ?', [$this->clubId]);
        }
        parent::tearDown();
    }

    public function testSeanceComposeeSelonLesThemes(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $id = bin2hex(random_bytes(4));
        $admin = (new Utilisateur())->setEmail("admin-$id@exemple.test")->setNom('Test')->setPrenom('Coach');
        $club = (new Club())->setNom("Club Test $id");
        static::getContainer()->get(CreationClub::class)->creer($club, $admin, 'motdepasse-admin');
        $this->clubId = $club->getId();
        $u15 = $em->getRepository(Categorie::class)->findOneBy(['club' => $club, 'nom' => 'U15']);
        $seniors = $em->getRepository(Categorie::class)->findOneBy(['club' => $club, 'secteur' => Categorie::SECTEUR_ELITE]);
        $client->loginUser($admin);

        $crawler = $client->request('GET', '/entrainements');
        self::assertSelectorExists('a[href^="/entrainements/automatique"]');
        $crawler = $client->request('GET', '/entrainements/automatique');
        self::assertResponseIsSuccessful();

        // Sans thème : on le demande.
        $form = $crawler->selectButton('Préparer la séance')->form();
        $client->submit($form, ['categorie' => $u15->getId(), 'date' => '2026-12-02', 'duree' => '90']);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('.erreur-champ', 'au moins une chose');

        // U15, 1 h 30, finition et défense.
        $form = $client->getCrawler()->selectButton('Préparer la séance')->form();
        $form['themes'][1]->tick(); // finition
        $form['themes'][3]->tick(); // défense
        $form['objectif'] = 'Être plus efficaces devant le but';
        $client->submit($form, ['categorie' => $u15->getId(), 'date' => '2026-12-02', 'duree' => '90']);
        self::assertResponseRedirects();
        $client->followRedirect();
        self::assertSelectorTextContains('.flash-succes', 'Séance de 90 minutes préparée');
        self::assertSelectorExists('button:contains("Télécharger en PDF")');

        $em->clear();
        $seance = $em->getRepository(Seance::class)->findOneBy(['categorie' => $u15->getId()]);
        self::assertSame('2026-12-02', $seance->getDate()->format('Y-m-d'));
        self::assertSame('Finition et frappe, Défense et placement', $seance->getTheme());
        self::assertStringStartsWith('Être plus efficaces devant le but', $seance->getObjectifs());
        $exercices = $seance->getExercices()->toArray();
        self::assertSame(90, array_sum(array_map(fn ($e) => $e->getDuree(), $exercices)), 'la durée totale est respectée');
        self::assertCount(5, $exercices, 'échauffement, 3 exercices, jeu final');
        self::assertStringStartsWith('Échauffement – ', $exercices[0]->getTitre());
        self::assertStringStartsWith('Jeu final – ', end($exercices)->getTitre());
        foreach ($exercices as $e) {
            self::assertNotEmpty($e->getConsignes());
        }

        // Seniors, 1 h, coups de pied arrêtés : le corner est proposé, et un jeu pour finir (niveau voisin ou match à thème).
        $crawler = $client->request('GET', '/entrainements/automatique');
        $form = $crawler->selectButton('Préparer la séance')->form();
        $form['themes'][6]->tick(); // coups de pied arrêtés
        $client->submit($form, ['categorie' => $seniors->getId(), 'date' => '2026-12-03', 'duree' => '60']);
        $em->clear();
        $seance = $em->getRepository(Seance::class)->findOneBy(['categorie' => $seniors->getId()]);
        $titres = array_map(fn ($e) => $e->getTitre(), $seance->getExercices()->toArray());
        self::assertSame(60, array_sum(array_map(fn ($e) => $e->getDuree(), $seance->getExercices()->toArray())));
        self::assertMatchesRegularExpression('/corner|coup franc|touche/i', implode(' | ', $titres), 'un exercice de coups de pied arrêtés');
        self::assertStringStartsWith('Jeu final – ', end($titres));

        // Avec l'effectif : 18 seniors, puis 5 absents de dernière minute et encore d'autres (6 présents).
        $crawler = $client->request('GET', '/entrainements/automatique');
        $form = $crawler->selectButton('Préparer la séance')->form();
        $form['themes'][5]->tick(); // pressing
        $client->submit($form, ['categorie' => $seniors->getId(), 'date' => '2026-12-05', 'duree' => '90', 'joueurs' => '18']);
        $em->clear();
        $seance = $em->getRepository(Seance::class)->findOneBy(['categorie' => $seniors->getId(), 'date' => new \DateTimeImmutable('2026-12-05')]);
        foreach ($seance->getExercices() as $e) {
            self::assertStringContainsString('Effectif du jour : 18 joueurs', $e->getConsignes());
        }
        $generateur = static::getContainer()->get(\App\Service\GenerateurSeance::class);
        $crawler = $client->request('GET', '/entrainements/' . $seance->getId());
        self::assertSelectorExists('form[action$="/adapter"] input[name="joueurs"]');
        $client->submit($crawler->selectButton('Adapter au nombre de joueurs')->form(), ['joueurs' => '6']);
        $client->followRedirect();
        self::assertSelectorTextContains('.flash-succes', 'Séance adaptée à 6 joueurs');
        $em->clear();
        $seance = $em->find(Seance::class, $seance->getId());
        self::assertSame(90, array_sum(array_map(fn ($e) => $e->getDuree(), $seance->getExercices()->toArray())), 'mêmes durées');
        self::assertSame('Pressing et récupération', $seance->getTheme(), 'même thème');
        $bibliotheque = static::getContainer()->get(\App\Service\BibliothequeExercices::class);
        foreach ($seance->getExercices() as $e) {
            $consignes = $e->getConsignes();
            self::assertSame(1, substr_count($consignes, 'Effectif du jour'), 'ligne remplacée, pas ajoutée');
            self::assertStringContainsString('Effectif du jour : 6 joueurs', $consignes);
            if (!str_starts_with($e->getTitre(), 'Jeu final')) {
                $cle = array_search(preg_replace('/^Échauffement – /u', '', $e->getTitre()), array_column($bibliotheque->tous(), 'titre', null), true);
                $cle = array_keys($bibliotheque->tous())[$cle];
                self::assertLessThanOrEqual(6, $generateur->taille($cle), $e->getTitre() . ' tient à 6');
            }
        }
    }
}
