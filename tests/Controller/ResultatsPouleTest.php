<?php

namespace App\Tests\Controller;

use App\Entity\Categorie;
use App\Entity\Classement;
use App\Entity\Club;
use App\Entity\ResultatPoule;
use App\Entity\Utilisateur;
use App\Service\CreationClub;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Championnat : scores des autres matchs de la poule saisis par journée, classement recalculé, pénalités.
 */
class ResultatsPouleTest extends WebTestCase
{
    private ?int $clubId = null;

    protected function tearDown(): void
    {
        if ($this->clubId) {
            $connexion = static::getContainer()->get(EntityManagerInterface::class)->getConnection();
            foreach (['resultat_poule', 'classement', 'utilisateur', 'categorie'] as $table) {
                $connexion->executeStatement("DELETE FROM $table WHERE club_id = ?", [$this->clubId]);
            }
            $connexion->executeStatement('DELETE FROM club WHERE id = ?', [$this->clubId]);
        }
        parent::tearDown();
    }

    public function testSaisieEtRecalcul(): void
    {
        $client = static::createClient();
        $id = bin2hex(random_bytes(4));
        $admin = (new Utilisateur())->setEmail("admin-$id@exemple.test")->setNom('Test')->setPrenom('Admin');
        $club = (new Club())->setNom("Club Test $id");
        static::getContainer()->get(CreationClub::class)->creer($club, $admin, 'motdepasse-admin');
        $this->clubId = $club->getId();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $u19 = $em->getRepository(Categorie::class)->findOneBy(['club' => $club, 'nom' => 'U19']);
        $saison = \App\Entity\Rencontre::saisonDe(new \DateTimeImmutable('today'));
        $ligne = fn (string $e, int $pts) => ['rang' => 0, 'equipe' => $e, 'points' => $pts, 'joues' => 10, 'gagnes' => 0, 'nuls' => 0, 'perdus' => 0, 'pour' => 10, 'contre' => 10, 'diff' => 0];
        $em->persist((new Classement($u19, $saison))->setClub($club)->setLignes([$ligne('ESTAC TROYES', 24), $ligne("FC D'ANNECY", 22), $ligne('PARIS FC', 13)]));
        $em->flush();

        $client->loginUser($admin);
        $crawler = $client->request('GET', '/championnat?categorie=' . $u19->getId() . '&saison=' . $saison);
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Officiel', $crawler->filter('.carte.bloc')->first()->text());
        // Saisie : Troyes perd 0-2 contre Annecy, le lendemain du classement officiel ; une ligne incomplète est signalée.
        $form = $crawler->selectButton('Enregistrer les matchs')->form();
        $client->submit($form, [
            'date' => (new \DateTimeImmutable('+1 day'))->format('Y-m-d'),
            'matchs[0][domicile]' => 'ESTAC TROYES', 'matchs[0][bd]' => '0', 'matchs[0][be]' => '2', 'matchs[0][exterieur]' => "FC D'ANNECY",
            'matchs[1][domicile]' => 'PARIS FC', 'matchs[1][bd]' => '1',
        ]);
        $crawler = $client->followRedirect();
        self::assertStringContainsString('1 match', $crawler->filter('.flash-succes')->text());
        self::assertStringContainsString('ligne 2', $crawler->filter('.flash-erreur')->text());
        $equipes = $crawler->filter('table.classement tbody tr td.fort')->each(fn ($n) => $n->text());
        self::assertSame(["FC D'ANNECY", 'ESTAC TROYES', 'PARIS FC'], $equipes, 'Annecy 25 pts passe devant Troyes 24');
        self::assertStringContainsString('Recalculé avec 1 résultat', $crawler->filter('.carte.bloc')->first()->text());

        // Pénalité de 2 points pour Paris : affichée à côté des points.
        $client->submit($crawler->filter('form.ajustement')->last()->form(['equipe' => 'PARIS FC', 'points' => '-2']));
        $crawler = $client->followRedirect();
        self::assertStringContainsString('11 (-2)', $crawler->filter('table.classement tbody tr')->last()->text());

        // Suppression du score : retour à l'officiel (avec la pénalité).
        $resultat = $em->getRepository(ResultatPoule::class)->findOneBy(['club' => $this->clubId]);
        $crawler = $client->request('GET', '/championnat?categorie=' . $u19->getId() . '&saison=' . $saison);
        $client->submit($crawler->filter('form[action$="/resultats/' . $resultat->getId() . '/supprimer"]')->form());
        $crawler = $client->followRedirect();
        self::assertSame(['ESTAC TROYES', "FC D'ANNECY", 'PARIS FC'], $crawler->filter('table.classement tbody tr td.fort')->each(fn ($n) => $n->text()));
    }

    public function testCalendrierDeLaPouleParJournee(): void
    {
        $client = static::createClient();
        $id = bin2hex(random_bytes(4));
        $admin = (new Utilisateur())->setEmail("admin-$id@exemple.test")->setNom('Test')->setPrenom('Admin');
        $club = (new Club())->setNom('Stade de Reims');
        static::getContainer()->get(CreationClub::class)->creer($club, $admin, 'motdepasse-admin');
        $this->clubId = $club->getId();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $u19 = $em->getRepository(Categorie::class)->findOneBy(['club' => $club, 'nom' => 'U19']);
        $saison = \App\Entity\Rencontre::saisonDe(new \DateTimeImmutable('today'));
        $ligne = fn (string $e, int $pts) => ['rang' => 0, 'equipe' => $e, 'points' => $pts, 'joues' => 10, 'gagnes' => 0, 'nuls' => 0, 'perdus' => 0, 'pour' => 10, 'contre' => 10, 'diff' => 0];
        $em->persist((new Classement($u19, $saison))->setClub($club)->setLignes([$ligne('ESTAC TROYES', 24), $ligne("FC D'ANNECY", 22), $ligne('PARIS FC', 13), $ligne('DIJON FCO', 11), $ligne('STADE DE REIMS', 9)]));
        $em->flush();
        $jour = new \DateTimeImmutable('+5 days');
        $date = fn (\DateTimeImmutable $d) => ['dim', 'lun', 'mar', 'mer', 'jeu', 'ven', 'sam'][(int) $d->format('w')] . ' ' . $d->format('d') . ' ' . ['jan', 'fév', 'mar', 'avr', 'mai', 'jui', 'jul', 'aoû', 'sep', 'oct', 'nov', 'déc'][(int) $d->format('n') - 1] . ' ' . $d->format('Y');
        // Page de la poule (FFF) copiée : journée 11 à venir, dont un match du club (ignoré), et une journée 12.
        $texte = implode("\n", [
            'Journée 11',
            $date($jour) . ' - 15h00', "FC D'ANNECY", "FC D'ANNECY", '15:00', 'ESTAC TROYES', 'ESTAC TROYES',
            $date($jour) . ' - 15h00', 'PARIS FC', 'PARIS FC', '15:00', 'DIJON FCO', 'DIJON FCO',
            $date($jour) . ' - 11h00', 'STADE DE REIMS', 'STADE DE REIMS', '11:00', 'CLERMONT FOOT 63', 'CLERMONT FOOT 63',
            'Journée 12',
            $date($jour->modify('+7 days')) . ' - 15h00', 'ESTAC TROYES', 'ESTAC TROYES', '15:00', 'PARIS FC', 'PARIS FC',
        ]);

        $client->loginUser($admin);
        $url = '/championnat?categorie=' . $u19->getId() . '&saison=' . $saison;
        $crawler = $client->request('GET', $url);
        $client->submit($crawler->selectButton('Importer les matchs de la poule')->form(['texte' => $texte]));
        $crawler = $client->followRedirect();
        self::assertStringContainsString('3 matchs ajoutés', $crawler->filter('.flash-succes')->text());
        self::assertSame(['Journée 11', 'Journée 12'], $crawler->filter('details.journee summary strong')->each(fn ($n) => $n->text()));
        self::assertSame(2, $crawler->filter('details.journee')->first()->filter('input[name$="[bd]"]')->count(), 'match du club exclu');

        // Scores de la journée 11 tapés dans les cases : Troyes battu, Paris gagne.
        $j11 = $em->getRepository(ResultatPoule::class)->findBy(['club' => $this->clubId, 'journee' => 11], ['domicile' => 'ASC']);
        $form = $crawler->filter('form#scores-1')->form();
        $form["scores[{$j11[0]->getId()}][bd]"] = '2';
        $form["scores[{$j11[0]->getId()}][be]"] = '0';
        $form["scores[{$j11[1]->getId()}][bd]"] = '1';
        $form["scores[{$j11[1]->getId()}][be]"] = '0';
        $client->submit($form);
        $crawler = $client->followRedirect();
        self::assertStringContainsString('2 scores enregistrés', $crawler->filter('.flash-succes')->text());
        self::assertSame(["FC D'ANNECY", 'ESTAC TROYES', 'PARIS FC', 'DIJON FCO', 'STADE DE REIMS'], $crawler->filter('table.classement tbody tr td.fort')->each(fn ($n) => $n->text()));

        // Nouveau copier-coller avec un score : le match existant est complété, pas recréé.
        $texte2 = implode("\n", ['Journée 12', $date($jour->modify('+7 days')) . ' - 15h00', 'ESTAC TROYES', 'ESTAC TROYES', '1', '-', '1', 'PARIS FC', 'PARIS FC']);
        $client->submit($crawler->selectButton('Importer les matchs de la poule')->form(['texte' => $texte2]));
        $crawler = $client->followRedirect();
        self::assertStringContainsString('0 match ajouté, 1 score mis à jour', $crawler->filter('.flash-succes')->text());
        self::assertCount(3, $em->getRepository(ResultatPoule::class)->findBy(['club' => $this->clubId]));
    }
}
