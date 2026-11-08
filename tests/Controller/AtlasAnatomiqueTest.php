<?php

namespace App\Tests\Controller;

use App\Entity\Blessure;
use App\Entity\Categorie;
use App\Entity\Club;
use App\Entity\Joueur;
use App\Entity\Utilisateur;
use App\Service\AtlasAnatomique;
use App\Service\CreationClub;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Atlas anatomique : depuis le dossier médical, corps entier → région → structure touchée,
 * qui ouvre « Nouvelle blessure » déjà remplie. Club fictif supprimé à la fin.
 */
class AtlasAnatomiqueTest extends WebTestCase
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
            foreach (['joueur', 'club'] as $table) {
                $connexion->executeStatement("DELETE FROM $table WHERE " . ('club' === $table ? 'id' : 'club_id') . ' = ?', [$this->clubId]);
            }
        }
        parent::tearDown();
    }

    public function testDuCorpsALaNouvelleBlessure(): void
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
        $em->flush();
        $dossier = '/infirmerie/dossiers/' . $joueur->getId();

        $this->client->loginUser($admin);
        $this->ouvrirMedical($this->client);

        // Dossier → « Localiser une blessure » → corps entier, de face puis de dos.
        $crawler = $this->client->request('GET', $dossier);
        $this->client->click($crawler->selectLink('Localiser une blessure')->link());
        self::assertResponseIsSuccessful();
        self::assertSelectorExists("a.point.region[href=\"$dossier/localiser/genou/gauche\"]");
        self::assertSelectorTextContains('.atlas-credits', 'CC BY-SA 4.0');
        $this->client->request('GET', "$dossier/localiser?vue=dos");
        self::assertSelectorExists("a.point.region[href=\"$dossier/localiser/fessier/droite\"]");

        // Toutes les régions et toutes leurs planches s'ouvrent, images présentes et créditées.
        $public = static::getContainer()->getParameter('kernel.project_dir') . '/public/images/atlas/';
        foreach (AtlasAnatomique::REGIONS as $code => $r) {
            foreach ($r['planches'] as $n => $p) {
                $this->client->request('GET', "$dossier/localiser/$code" . (($r['cote'] ?? false) ? '/droite' : '') . "?planche=$n");
                self::assertResponseIsSuccessful("$code / $n");
                foreach ($p['images'] as $image) {
                    self::assertFileExists($public . $image);
                }
                self::assertSelectorExists('.atlas-credits a');
            }
        }
        $this->client->request('GET', "$dossier/localiser/inconnue");
        self::assertResponseStatusCodeSame(404);

        // Genou gauche → « Ligament croisé antérieur (LCA) » : nouvelle blessure déjà remplie.
        $crawler = $this->client->request('GET', "$dossier/localiser/genou/gauche");
        self::assertSelectorTextContains('h1', 'Genou gauche');
        $crawler = $this->client->click($crawler->selectLink('Ligament croisé antérieur (LCA)')->link());
        self::assertResponseIsSuccessful();
        self::assertInputValueSame('blessure[zone]', 'Ligament croisé antérieur (LCA)');
        self::assertInputValueSame('blessure[localisation]', 'Genou gauche');
        self::assertSelectorExists('select[name="blessure[membre]"] option[value="Membre inférieur"][selected]');

        $this->client->submit($crawler->selectButton('Enregistrer')->form());
        self::assertResponseRedirects($dossier . '#infirmerie');
        $blessure = $this->em()->getRepository(Blessure::class)->findOneBy(['joueur' => $joueur]);
        self::assertSame('Genou gauche', $blessure->getLocalisation());
        self::assertSame('Membre inférieur', $blessure->getMembre());

        // Nouvelle blessure depuis l'Infirmerie (joueur à choisir) : bouton qui ouvre l'atlas du joueur choisi.
        $this->client->request('GET', '/infirmerie/blessures/nouvelle');
        self::assertSelectorExists('[data-controller="localiser"][data-localiser-url-value="/infirmerie/dossiers/0/localiser"] button');
        $this->client->request('GET', '/infirmerie/blessures/nouvelle?joueur=' . $joueur->getId());
        self::assertSelectorExists(".atlas-acces a[href=\"$dossier/localiser\"]");

        // « Autre » : structure libre ; un membre inconnu est ignoré.
        $this->client->request('GET', '/infirmerie/blessures/nouvelle?joueur=' . $joueur->getId() . '&localisation=Abdomen&membre=Inconnu&zone=Hernie');
        self::assertInputValueSame('blessure[zone]', 'Hernie');
        self::assertSelectorNotExists('select[name="blessure[membre]"] option[selected][value]:not([value=""])');
    }

    private function em(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }
}
