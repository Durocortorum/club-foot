<?php

namespace App\Tests\Controller;

use App\Entity\Blessure;
use App\Entity\Categorie;
use App\Entity\Club;
use App\Entity\Joueur;
use App\Entity\Utilisateur;
use App\Service\CreationClub;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Données médicales : droit donné par un médecin (ou l'administrateur) avec échéance,
 * puis consultation ouverte par un code reçu par e-mail. Club fictif supprimé à la fin.
 */
class AccesMedicalTest extends WebTestCase
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
            foreach (['rencontre', 'joueur', 'club'] as $table) {
                $connexion->executeStatement("DELETE FROM $table WHERE " . ('club' === $table ? 'id' : 'club_id') . ' = ?', [$this->clubId]);
            }
        }
        parent::tearDown();
    }

    public function testCodeEtEcheance(): void
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
        $em->persist((new Blessure())->setClub($club)->setJoueur($joueur)->setDate(new \DateTimeImmutable('-3 days'))->setType('Entorse')->setLocalisation('Cheville'));
        $medecin = $this->compteEncadrement($club, "medecin-$id@exemple.test", 'Docteur', ['ROLE_MEDECIN']);
        $kine = $this->compteEncadrement($club, "kine-$id@exemple.test", 'Kiné', []);
        $em->flush();

        // --- Le médecin ouvre le tableau des droits médicaux (pas les autres pages d'administration).
        $this->client->loginUser($medecin);
        $crawler = $this->client->request('GET', '/utilisateurs/droits');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Droits médicaux');
        $this->client->request('GET', '/utilisateurs');
        self::assertResponseStatusCodeSame(403);
        $jeton = $crawler->filter('input[name="_token"]')->attr('value');

        // Il donne « Infirmerie : modifier » au kiné pour 1 semaine ; il ne peut pas toucher aux secteurs.
        foreach (['1', '2'] as $niveau) {
            $this->client->request('POST', '/utilisateurs/' . $kine->getId() . '/droit',
                ['droit' => 'infirmerie', 'niveau' => $niveau, 'duree' => '7', '_token' => $jeton], [], ['HTTP_ACCEPT' => 'application/json']);
            self::assertResponseIsSuccessful();
        }
        $reponse = json_decode($this->client->getResponse()->getContent(), true);
        self::assertSame((new \DateTimeImmutable('+7 days'))->format('d/m/Y'), $reponse['echeances']['infirmerie']);
        $this->client->request('POST', '/utilisateurs/' . $kine->getId() . '/droit',
            ['droit' => 'ROLE_FORMATION', 'niveau' => '1', '_token' => $jeton], [], ['HTTP_ACCEPT' => 'application/json']);
        self::assertResponseStatusCodeSame(422);
        $this->client->request('POST', '/utilisateurs/' . $kine->getId() . '/droit',
            ['droit' => 'visite_medicale', 'niveau' => '1', 'duree' => '9999', '_token' => $jeton], [], ['HTTP_ACCEPT' => 'application/json']);
        self::assertResponseStatusCodeSame(422);

        // --- Le kiné : l'infirmerie demande d'abord un code par e-mail.
        $this->client->loginUser($this->compte($kine));
        $this->client->request('GET', '/infirmerie');
        self::assertResponseRedirects('/medical/code?retour=/infirmerie');
        $crawler = $this->client->request('GET', '/joueurs/' . $joueur->getId());
        self::assertSelectorExists('#medical-verrou');
        self::assertSelectorTextNotContains('main', 'Entorse');

        $crawler = $this->client->request('GET', '/medical/code?retour=/infirmerie');
        $this->client->submit($crawler->selectButton('Recevoir un code par e-mail')->form());
        $code = $this->codeRecu();
        $crawler = $this->client->followRedirect();
        $faux = '000000' === $code ? '111111' : '000000';
        $this->client->submit($crawler->selectButton('Ouvrir l\'accès')->form(), ['code' => $faux]);
        $crawler = $this->client->followRedirect();
        self::assertSelectorTextContains('.flash-erreur', 'Code incorrect');
        $this->client->submit($crawler->selectButton('Ouvrir l\'accès')->form(), ['code' => $code]);
        self::assertResponseRedirects('/infirmerie');
        $this->client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.medical-ouvert', 'Médical jusqu');
        $this->client->request('GET', '/joueurs/' . $joueur->getId());
        self::assertSelectorNotExists('#medical-verrou');
        self::assertSelectorTextContains('#infirmerie', 'Entorse');

        // Il referme : nouveau code nécessaire.
        $crawler = $this->client->request('GET', '/infirmerie');
        $this->client->submit($crawler->filter('.medical-ouvert')->form());
        $this->client->request('GET', '/infirmerie');
        self::assertResponseRedirects('/medical/code?retour=/infirmerie');

        // --- Droit échu : plus d'accès du tout, même avec un code.
        $this->em()->getConnection()->executeStatement('UPDATE utilisateur SET infirmerie_jusqu_au = ? WHERE id = ?', [(new \DateTimeImmutable('-1 day'))->format('Y-m-d'), $kine->getId()]);
        $this->client->loginUser($this->compte($kine));
        $this->client->request('GET', '/infirmerie');
        self::assertResponseStatusCodeSame(403);
        $this->client->request('GET', '/medical/code');
        self::assertResponseStatusCodeSame(403);

        // --- L'administrateur (non médecin) garde l'accès, lui aussi par code.
        $this->client->loginUser($admin);
        $this->client->request('GET', '/infirmerie');
        self::assertResponseRedirects();
        $this->ouvrirMedical($this->client);
        $this->client->request('GET', '/infirmerie');
        self::assertResponseIsSuccessful();
    }

    /** @param list<string> $roles */
    private function compteEncadrement(Club $club, string $email, string $prenom, array $roles): Utilisateur
    {
        $u = (new Utilisateur())->setClub($club)->setEmail($email)->setNom('Test')->setPrenom($prenom)->setRolesAttribues($roles)->setPassword('inutile');
        $this->em()->persist($u);

        return $u;
    }

    private function compte(Utilisateur $u): Utilisateur
    {
        $em = $this->em();
        $em->clear();

        return $em->getRepository(Utilisateur::class)->find($u->getId());
    }

    private function em(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }
}
