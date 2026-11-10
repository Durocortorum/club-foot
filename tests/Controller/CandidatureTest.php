<?php

namespace App\Tests\Controller;

use App\Entity\Candidature;
use App\Entity\Club;
use App\Entity\ContactMedical;
use App\Entity\Dirigeant;
use App\Entity\Educateur;
use App\Entity\Utilisateur;
use App\Service\CreationClub;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Candidatures de l'encadrement : saisie avec CV en PDF, filtre, intégration en fiche éducateur
 * ou contact médical, réservé à l'administration.
 */
class CandidatureTest extends WebTestCase
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
            $em = static::getContainer()->get(EntityManagerInterface::class);
            $connexion = $em->getConnection();
            foreach ($connexion->fetchFirstColumn('SELECT cv FROM candidature WHERE club_id = ? AND cv IS NOT NULL', [$this->clubId]) as $cv) {
                @unlink(static::getContainer()->getParameter('kernel.project_dir') . '/var/documents/' . $cv);
            }
            foreach (['candidature', 'educateur', 'dirigeant', 'contact_medical', 'utilisateur'] as $table) {
                $connexion->executeStatement("DELETE FROM $table WHERE club_id = ?", [$this->clubId]);
            }
            $connexion->executeStatement('DELETE FROM club WHERE id = ?', [$this->clubId]);
        }
        parent::tearDown();
    }

    public function testCandidaturesEncadrement(): void
    {
        $id = bin2hex(random_bytes(4));
        $admin = (new Utilisateur())->setEmail("admin-$id@exemple.test")->setNom('Test')->setPrenom('Admin');
        $club = (new Club())->setNom("Club Test $id");
        static::getContainer()->get(CreationClub::class)->creer($club, $admin, 'motdepasse-admin');
        $this->clubId = $club->getId();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $coach = (new Utilisateur())->setClub($club)->setEmail("coach-$id@exemple.test")->setNom('Durand')->setPrenom('Coach')->setRolesAttribues(['ROLE_FORMATION'])->setPassword('x');
        $em->persist($coach);
        $em->flush();

        // Candidature d'éducateur avec CV en PDF, depuis l'onglet du recrutement.
        $this->client->loginUser($admin);
        $crawler = $this->client->request('GET', '/recrutement/detections');
        $crawler = $this->client->click($crawler->selectLink('Candidatures encadrement')->link());
        $crawler = $this->client->click($crawler->selectLink('+ Nouvelle candidature')->link());
        $pdf = sys_get_temp_dir() . "/cv-$id.pdf";
        file_put_contents($pdf, "%PDF-1.4\n1 0 obj << /Type /Catalog >> endobj\ntrailer << /Root 1 0 R >>\n%%EOF\n");
        $form = $crawler->selectButton('Enregistrer')->form([
            'candidature[nom]' => 'Martin',
            'candidature[prenom]' => 'Lucas',
            'candidature[posteVise]' => 'educateurs',
            'candidature[fonction]' => 'Éducateur U13',
            'candidature[telephone]' => '06 11 22 33 44',
            'candidature[email]' => "lucas-$id@exemple.test",
            'candidature[diplomes]' => 'BMF',
            'candidature[experience]' => '3 saisons en U11 au FC Voisin.',
            'candidature[disponibilites]' => 'Mercredi et samedi',
            'candidature[statut]' => 'entretien',
        ]);
        $form['candidature[document]']->upload(new UploadedFile($pdf, 'cv.pdf', 'application/pdf', null, true));
        $this->client->submit($form);
        $crawler = $this->client->followRedirect();
        self::assertStringContainsString('Entretien prévu', $crawler->filter('h1 + p')->text());
        $lucas = $em->getRepository(Candidature::class)->findOneBy(['club' => $this->clubId, 'nom' => 'MARTIN']);
        self::assertNotNull($lucas->getCv());
        $this->client->click($crawler->selectLink('Voir le CV')->link());
        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('content-type', 'application/pdf');

        // Candidature de kiné, puis filtre par poste.
        $kine = (new Candidature())->setClub($em->find(Club::class, $this->clubId))->setNom('Petit')->setPrenom('Sarah')->setPosteVise('medical')->setFonction('Kinésithérapeute')->setTelephone('06 99 88 77 66');
        $em->persist($kine);
        $em->flush();
        $crawler = $this->client->request('GET', '/recrutement/candidatures?poste=medical');
        self::assertCount(1, $crawler->filter('tbody tr'));
        self::assertStringContainsString('PETIT', $crawler->filter('tbody')->text());

        // Lucas rejoint le club : fiche éducateur avec ses coordonnées et son parcours.
        $crawler = $this->client->request('GET', '/recrutement/candidatures/' . $lucas->getId());
        $this->client->submit($crawler->selectButton('Intégrer à l\'encadrement')->form());
        $crawler = $this->client->followRedirect();
        $em->clear();
        $educateur = $em->getRepository(Educateur::class)->findOneBy(['club' => $this->clubId, 'nom' => 'MARTIN']);
        self::assertNotNull($educateur);
        self::assertSame('Éducateur U13', $educateur->getFonction());
        self::assertSame('BMF', $educateur->getDiplome());
        self::assertSame('06 11 22 33 44', $educateur->getTelPortable1());
        self::assertStringContainsString('Disponibilités : Mercredi et samedi', $educateur->getMemo());
        $lucas = $em->find(Candidature::class, $lucas->getId());
        self::assertSame('retenu', $lucas->getStatut());
        self::assertSame('educateurs:' . $educateur->getId(), $lucas->getFiche());
        $crawler = $this->client->request('GET', '/recrutement/candidatures/' . $lucas->getId());
        self::assertCount(0, $crawler->selectButton('Intégrer à l\'encadrement'));
        self::assertStringContainsString('A rejoint le club', $crawler->filter('.titre-page')->text());

        // Sarah rejoint le corps médical : contact de l'infirmerie.
        $crawler = $this->client->request('GET', '/recrutement/candidatures/' . $kine->getId());
        $this->client->submit($crawler->selectButton('Intégrer au corps médical')->form());
        $contact = $em->getRepository(ContactMedical::class)->findOneBy(['club' => $this->clubId, 'nom' => 'PETIT']);
        self::assertSame('Kinésithérapeute', $contact->getProfession());
        self::assertSame('06 99 88 77 66', $contact->getTelephone());

        // Un ancien dirigeant repostule (nom écrit autrement) : pas de doublon, sa fiche est reprise et réactivée.
        $club = $em->find(Club::class, $this->clubId);
        $ancien = (new Dirigeant())->setClub($club)->setNom('Lefèvre')->setPrenom('Jean')->setAncien(true);
        $retour = (new Candidature())->setClub($club)->setNom('lefevre')->setPrenom('jean')->setPosteVise('dirigeants');
        $em->persist($ancien);
        $em->persist($retour);
        $em->flush();
        $crawler = $this->client->request('GET', '/recrutement/candidatures/' . $retour->getId());
        self::assertStringContainsString('Déjà connu au club', $crawler->filter('main')->text());
        $this->client->submit($crawler->selectButton('Rattacher à sa fiche existante')->form());
        $em->clear();
        self::assertCount(1, $em->getRepository(Dirigeant::class)->findBy(['club' => $this->clubId]));
        self::assertFalse($em->find(Dirigeant::class, $ancien->getId())->isAncien());
        self::assertSame('dirigeants:' . $ancien->getId(), $em->find(Candidature::class, $retour->getId())->getFiche());

        // Suppression : la candidature et son CV disparaissent.
        $cv = static::getContainer()->getParameter('kernel.project_dir') . '/var/documents/' . $lucas->getCv();
        self::assertFileExists($cv);
        $crawler = $this->client->request('GET', '/recrutement/candidatures/' . $lucas->getId());
        $this->client->submit($crawler->filter('form[action$="/supprimer"]')->form());
        self::assertFileDoesNotExist($cv);

        // Un éducateur voit le recrutement des joueurs, pas les candidatures.
        $this->client->loginUser($coach);
        $crawler = $this->client->request('GET', '/recrutement/detections');
        self::assertCount(0, $crawler->selectLink('Candidatures encadrement'));
        $this->client->request('GET', '/recrutement/candidatures');
        self::assertResponseStatusCodeSame(403);
    }
}
