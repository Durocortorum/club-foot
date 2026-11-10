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
        $form = $crawler->selectButton('Enregistrer les scores')->form();
        $client->submit($form, [
            'date' => (new \DateTimeImmutable('+1 day'))->format('Y-m-d'),
            'matchs[0][domicile]' => 'ESTAC TROYES', 'matchs[0][bd]' => '0', 'matchs[0][be]' => '2', 'matchs[0][exterieur]' => "FC D'ANNECY",
            'matchs[1][domicile]' => 'PARIS FC', 'matchs[1][bd]' => '1',
        ]);
        $crawler = $client->followRedirect();
        self::assertStringContainsString('1 résultat', $crawler->filter('.flash-succes')->text());
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
}
