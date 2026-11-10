<?php

namespace App\Tests\Controller;

use App\Entity\Club;
use App\Entity\Utilisateur;
use App\Service\CreationClub;
use App\Service\ImportClassement;
use App\Service\ImportMatchs;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Import depuis la FFF : la page du bouton « ➜ Club Foot », et la lecture par les imports existants
 * du texte que produit ce bouton (calendrier : un bloc par match ; classement : une ligne par équipe).
 */
class ImportFffTest extends WebTestCase
{
    private ?int $clubId = null;

    protected function tearDown(): void
    {
        if ($this->clubId) {
            $connexion = static::getContainer()->get(EntityManagerInterface::class)->getConnection();
            $connexion->executeStatement('DELETE FROM utilisateur WHERE club_id = ?', [$this->clubId]);
            $connexion->executeStatement('DELETE FROM categorie WHERE club_id = ?', [$this->clubId]);
            $connexion->executeStatement('DELETE FROM club WHERE id = ?', [$this->clubId]);
        }
        parent::tearDown();
    }

    public function testPageDuBouton(): void
    {
        $client = static::createClient();
        $id = bin2hex(random_bytes(4));
        $admin = (new Utilisateur())->setEmail("admin-$id@exemple.test")->setNom('Test')->setPrenom('Admin');
        $club = (new Club())->setNom("Club Test $id");
        static::getContainer()->get(CreationClub::class)->creer($club, $admin, 'motdepasse-admin');
        $this->clubId = $club->getId();

        $client->loginUser($admin);
        $crawler = $client->request('GET', '/matchs/importer');
        $crawler = $client->click($crawler->selectLink('➜ Importer en un clic depuis la FFF')->link());
        self::assertResponseIsSuccessful();
        $lien = $crawler->filter('a.bouton-favori')->attr('href');
        self::assertStringStartsWith('javascript:', $lien);
        $code = rawurldecode(substr($lien, \strlen('javascript:')));
        self::assertStringContainsString('app-match-score', $code);
        self::assertStringContainsString('/import-fff', $code, 'le bouton ouvre cette page');
        self::assertStringNotContainsString("\n", $code, 'sur une seule ligne');
        // Formulaire prêt pour les deux imports (jetons des deux formulaires).
        self::assertNotEmpty($crawler->filter('input[data-calendrier]')->attr('data-calendrier'));
        self::assertNotEmpty($crawler->filter('input[data-classement]')->attr('data-classement'));
    }

    public function testTexteDuBoutonLuParLesImports(): void
    {
        // Calendrier tel que l'envoie le bouton (page « Résultats et calendrier » de la FFF).
        $calendrier = implode("\n\n", [
            "dim 08 nov 2026 - 14h30\nCHAMPIONNAT NATIONAL U19 - U19 - U18 Journée 10\nMONTFERMEIL FC\n3 - 0\nSTADE DE REIMS",
            "dim 15 nov 2026 - 11h00\nCHAMPIONNAT NATIONAL U19 - U19 - U18 Journée 11\nSTADE DE REIMS\n11:00\nCLERMONT FOOT 63",
            "sam 15 aoû 2026 - 11h30\nMATCHES AMICAUX U19/U18 LFHF - U19 - U18 Journée 33\nVALENCIENNES FC\n1 - 0\nSTADE DE REIMS",
        ]);
        $r = (new ImportMatchs())->analyser($calendrier);
        self::assertSame('STADE DE REIMS', $r['nous']);
        self::assertCount(3, $r['matchs']);
        [$amical, $montfermeil, $clermont] = $r['matchs'];
        self::assertSame('Amical', $amical['competition']);
        self::assertSame(['MONTFERMEIL FC', 3, 0, false], [$montfermeil['adversaire'], $montfermeil['butsDomicile'], $montfermeil['butsExterieur'], $montfermeil['aDomicile']]);
        self::assertSame(['CLERMONT FOOT 63', '11h00', null, true], [$clermont['adversaire'], $clermont['heure'], $clermont['butsDomicile'], $clermont['aDomicile']]);

        // Classement tel que l'envoie le bouton : cellules séparées par des tabulations (progression vide ou « 2 »).
        $classement = implode("\n", [
            "1\t\tESTAC TROYES\t24\t10\t7\t3\t0\t0\t0\t25\t11\t14\t",
            "2\t\tFC D'ANNECY\t20\t10\t6\t2\t2\t0\t0\t19\t11\t8\t",
            "3\t\tCLERMONT FOOT 63\t17\t10\t5\t2\t3\t0\t0\t18\t9\t9\t",
            "4\t2\tMONTFERMEIL FC\t17\t10\t5\t2\t3\t0\t0\t13\t8\t5\t",
        ]);
        $lignes = (new ImportClassement())->analyser($classement);
        self::assertCount(4, $lignes);
        self::assertSame(['rang' => 4, 'equipe' => 'MONTFERMEIL FC', 'points' => 17, 'joues' => 10], array_intersect_key($lignes[3], array_flip(['rang', 'equipe', 'points', 'joues'])));
        self::assertSame([5, 2, 3, 13, 8], [$lignes[3]['gagnes'], $lignes[3]['nuls'], $lignes[3]['perdus'], $lignes[3]['pour'], $lignes[3]['contre']]);
    }
}
