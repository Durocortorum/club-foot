<?php

namespace App\Tests\Controller;

use App\Entity\Categorie;
use App\Entity\Club;
use App\Entity\Joueur;
use App\Entity\Utilisateur;
use App\Service\CreationClub;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Import de joueurs depuis un CSV enregistré par Excel (Windows-1252, « ; »), sans colonne Catégorie et avec des colonnes
 * inconnues (Âge, Taille, IMC…) : le formulaire sort de Turbo (aperçu affiché en réponse directe), aperçu, confirmation.
 */
class ImportJoueursTest extends WebTestCase
{
    private ?int $clubId = null;

    protected function tearDown(): void
    {
        if ($this->clubId) {
            $db = static::getContainer()->get(EntityManagerInterface::class)->getConnection();
            foreach (['utilisateur', 'joueur', 'categorie'] as $table) {
                $db->executeStatement("DELETE FROM $table WHERE club_id = ?", [$this->clubId]);
            }
            $db->executeStatement('DELETE FROM club WHERE id = ?', [$this->clubId]);
        }
        parent::tearDown();
    }

    public function testImportFichierExcel(): void
    {
        $client = static::createClient();
        $id = bin2hex(random_bytes(4));
        $admin = (new Utilisateur())->setEmail("admin-$id@exemple.test")->setNom('Test')->setPrenom('Admin');
        $club = (new Club())->setNom("FC Import $id");
        static::getContainer()->get(CreationClub::class)->creer($club, $admin, 'motdepasse-admin');
        $this->clubId = $club->getId();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $seniors = $em->getRepository(Categorie::class)->findOneBy(['club' => $club, 'nom' => 'SENIORS']);
        $client->loginUser($admin);

        $crawler = $client->request('GET', '/joueurs/import');
        $form = $crawler->filter('form[enctype="multipart/form-data"]');
        self::assertSame('false', $form->attr('data-turbo'), 'aperçu affiché en réponse directe : hors de Turbo');

        $csv = mb_convert_encoding("Nom;Prénom;Age;Poste;Taille;Poids;IMC;VMA;;;\r\nBEAUGRAND ;Jeffrey;37;G;1.97;116.5;30.0;15;;;\r\nLEBEGUE;Kévin;36;Mill;1.7;84;29.1;15.5;;;\r\nHUGE;Cameron;;;;;#DIV/0!;;;;\r\n", 'Windows-1252', 'UTF-8');
        $fichier = tempnam(sys_get_temp_dir(), 'csv');
        file_put_contents($fichier, $csv);
        $crawler = $client->submit($form->form(['categorie' => $seniors->getId()]), ['fichier' => new UploadedFile($fichier, 'Saison 2026_2027.csv', 'text/csv', null, true)]);
        self::assertResponseIsSuccessful();
        $page = $crawler->filter('main')->text();
        self::assertStringContainsString('LEBEGUE', $page);
        self::assertStringContainsString('Kévin', $page, 'accents d\'Excel (Windows-1252) relus');

        $client->submit($crawler->selectButton('Importer')->form());
        self::assertResponseRedirects();
        $em->clear();
        $noms = array_map(fn (Joueur $j) => $j->getNom(), $em->getRepository(Joueur::class)->findBy(['club' => $this->clubId], ['nom' => 'ASC']));
        self::assertSame(['BEAUGRAND', 'HUGE', 'LEBEGUE'], $noms);
        unlink($fichier);
    }
}
