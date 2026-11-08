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

        // Arrêt envisagé : 6 semaines → reprise prévue affichée dans le dossier tant que la reprise n'est pas saisie.
        $this->client->submit($crawler->selectButton('Enregistrer')->form(), [
            'blessure[date]' => '2026-01-31', 'blessure[arretPrevu]' => '6', 'blessure[uniteArret]' => 'semaines',
        ]);
        self::assertResponseRedirects($dossier . '#infirmerie');
        $blessure = $this->em()->getRepository(Blessure::class)->findOneBy(['joueur' => $joueur]);
        self::assertSame('Genou gauche', $blessure->getLocalisation());
        self::assertSame('Membre inférieur', $blessure->getMembre());
        self::assertSame('6 semaines', $blessure->getArretPrevuLibelle());
        self::assertSame('2026-03-14', $blessure->getRepriseEnvisagee()->format('Y-m-d'));
        $this->client->request('GET', $dossier);
        self::assertSelectorTextContains('#infirmerie', 'prévue vers le 14/03/2026');
        self::assertSelectorTextContains('#infirmerie', '≈ 6 semaines');
        // Mois : 31 janvier + 1 mois = fin février ; 1 jour au singulier.
        $blessure->setArretPrevu(1)->setUniteArret('mois');
        self::assertSame('2026-02-28', $blessure->getRepriseEnvisagee()->format('Y-m-d'));
        self::assertSame('1 mois', $blessure->getArretPrevuLibelle());
        $blessure->setUniteArret('jours');
        self::assertSame('1 jour', $blessure->getArretPrevuLibelle());
        $this->em()->clear();

        // Nouvelle blessure depuis l'Infirmerie (joueur à choisir) : bouton qui ouvre l'atlas du joueur choisi.
        // Ordre : date, catégorie, joueur, puis localisation ; chaque joueur porte sa catégorie (filtre).
        $crawler = $this->client->request('GET', '/infirmerie/blessures/nouvelle');
        self::assertSelectorExists('form[data-controller="blessure"][data-blessure-atlas-value="/infirmerie/dossiers/0/localiser"] .atlas-acces button');
        $noms = $crawler->filter('form [name^="blessure["]')->each(fn ($c) => $c->attr('name'));
        self::assertSame(['blessure[date]', 'blessure[categorie]', 'blessure[joueur]', 'blessure[localisation]', 'blessure[zone]', 'blessure[membre]'], \array_slice($noms, 0, 6));
        self::assertSelectorExists(\sprintf('select[name="blessure[joueur]"] option[value="%d"][data-categorie="%d"]', $joueur->getId(), $categorie->getId()));
        // Depuis le dossier : catégorie et joueur fixés.
        $this->client->request('GET', '/infirmerie/blessures/nouvelle?joueur=' . $joueur->getId());
        self::assertSelectorExists(\sprintf('select[name="blessure[categorie]"][disabled] option[value="%d"][selected]', $categorie->getId()));

        // La date saisie traverse l'atlas et revient dans le formulaire.
        $crawler = $this->client->request('GET', "$dossier/localiser/cuisse/droite?date=2026-10-03");
        $this->client->click($crawler->selectLink('Biceps fémoral')->link());
        self::assertInputValueSame('blessure[date]', '2026-10-03');
        self::assertInputValueSame('blessure[localisation]', 'Cuisse droite');
        $this->client->request('GET', "$dossier/localiser?date=n'importe-quoi");
        self::assertSelectorNotExists('a[href*="date="]');

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
