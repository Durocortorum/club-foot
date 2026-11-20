<?php

namespace App\Tests\Controller;

use App\Entity\Categorie;
use App\Entity\Club;
use App\Entity\Educateur;
use App\Entity\Rencontre;
use App\Entity\Utilisateur;
use App\Service\CreationClub;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Rapports de match réservés à l'éducateur responsable de l'équipe et aux personnes qu'il désigne
 * (plus l'administrateur) ; les autres voient le match sans le modifier ; trace de la dernière modification.
 */
class RapportMatchDroitsTest extends WebTestCase
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
            $connexion->executeStatement('DELETE FROM rencontre WHERE club_id = ?', [$this->clubId]);
            $connexion->executeStatement('DELETE FROM categorie_redacteur_match WHERE categorie_id IN (SELECT id FROM categorie WHERE club_id = ?)', [$this->clubId]);
            $connexion->executeStatement('UPDATE categorie SET responsable_matchs_id = NULL WHERE club_id = ?', [$this->clubId]);
            $connexion->executeStatement('DELETE FROM responsable_secteur WHERE club_id = ?', [$this->clubId]);
            $connexion->executeStatement('DELETE FROM utilisateur WHERE club_id = ?', [$this->clubId]);
            $connexion->executeStatement('DELETE FROM educateur_categorie WHERE educateur_id IN (SELECT id FROM educateur WHERE club_id = ?)', [$this->clubId]);
            $connexion->executeStatement('DELETE FROM educateur WHERE club_id = ?', [$this->clubId]);
            $connexion->executeStatement('DELETE FROM club WHERE id = ?', [$this->clubId]);
        }
        parent::tearDown();
    }

    /** L'éducateur de l'équipe (fiche Encadrement) devient responsable dès qu'il a un compte avec la même adresse. */
    public function testEducateurDeLaFicheEncadrement(): void
    {
        $id = bin2hex(random_bytes(4));
        $admin = (new Utilisateur())->setEmail("admin-$id@exemple.test")->setNom('Admin')->setPrenom('Paul');
        $club = (new Club())->setNom("Club Test $id");
        static::getContainer()->get(CreationClub::class)->creer($club, $admin, 'motdepasse-admin');
        $this->clubId = $club->getId();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $equipe = $em->getRepository(Categorie::class)->findOneBy(['club' => $club], ['ordre' => 'ASC']);
        $role = Categorie::SECTEURS[$equipe->getSecteur()]['role'];

        $fiche = (new Educateur())->setClub($club)->setNom('Benali')->setPrenom('Karim')->setEmail("Karim-$id@exemple.test")->addEquipe($equipe);
        $adjoint = (new Utilisateur())->setClub($club)->setEmail("adjoint-$id@exemple.test")->setNom('Aide')->setPrenom('Julie')->setRoles([$role]);
        $match = (new Rencontre())->setClub($club)->setCategorie($equipe)->setDate(new \DateTimeImmutable('today'))
            ->setDomicile("CLUB TEST $id")->setExterieur('FC METZ');
        foreach ([$fiche, $adjoint, $match] as $o) {
            $em->persist($o);
        }
        $em->flush();
        $voir = '/matchs/' . $match->getId();

        // Fiche sans compte : signalé, et le match reste ouvert au secteur.
        $this->client->loginUser($adjoint);
        $this->client->request('GET', $voir);
        self::assertSelectorTextContains('.trace-modif', 'Karim BENALI, éducateur de l\'équipe, n\'a pas encore de compte');
        $this->client->request('GET', $voir . '/modifier');
        self::assertResponseIsSuccessful();

        // Il reçoit un compte avec l'adresse de sa fiche : il devient responsable, sans réglage de l'administrateur.
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $karim = (new Utilisateur())->setClub($em->find(Club::class, $this->clubId))->setEmail("karim-$id@exemple.test")->setNom('Benali')->setPrenom('Karim')->setRoles([$role]);
        $em->persist($karim);
        $em->flush();
        $this->client->request('GET', $voir . '/modifier');
        self::assertResponseStatusCodeSame(403);
        $this->client->loginUser($karim);
        $this->client->request('GET', $voir);
        self::assertSelectorTextContains('.trace-modif', 'modifiables par Karim BENALI (éducateur de l\'équipe)');
        $this->client->request('GET', $voir . '/modifier');
        self::assertResponseIsSuccessful();
        // Il peut lui-même désigner d'autres personnes.
        $this->client->request('GET', '/matchs/equipe/' . $equipe->getId() . '/responsables');
        self::assertResponseIsSuccessful();

        // Mon compte : sa fiche (même adresse) et son équipe, avec les raccourcis vers le prochain match.
        $crawler = $this->client->request('GET', '/mon-compte');
        self::assertSelectorTextContains('.mon-equipe', $equipe->getNom());
        self::assertSelectorTextContains('.mon-equipe', 'Responsable des matchs');
        self::assertSelectorTextContains('.mon-equipe', 'FC METZ');
        self::assertCount(1, $crawler->filter('.mon-equipe a[href$="/convocation"]'));
        // Il met à jour ses coordonnées lui-même, sans pouvoir changer ses équipes ni sa fonction.
        $form = $this->client->click($crawler->selectLink('Mettre à jour mes coordonnées')->link())->selectButton('Enregistrer')->form();
        self::assertFalse($form->has('ma_fiche[fonction]'));
        $form['ma_fiche[telPortable1]'] = '06 11 22 33 44';
        $form['ma_fiche[ville]'] = 'Metz';
        $this->client->submit($form);
        self::assertResponseRedirects('/mon-compte');
        $fiche = static::getContainer()->get(EntityManagerInterface::class)->find(Educateur::class, $fiche->getId());
        self::assertSame(['06 11 22 33 44', 'Metz'], [$fiche->getTelPortable1(), $fiche->getVille()]);
        self::assertCount(1, $fiche->getEquipes());

        // Un autre compte n'a pas de fiche à modifier.
        $this->client->loginUser($adjoint);
        $this->client->request('GET', '/mon-compte/fiche/educateurs');
        self::assertResponseStatusCodeSame(403);
    }

    /** Le responsable de secteur reçoit le droit du secteur et modifie les matchs de toutes ses équipes. */
    public function testResponsableDeSecteur(): void
    {
        $id = bin2hex(random_bytes(4));
        $admin = (new Utilisateur())->setEmail("admin-$id@exemple.test")->setNom('Admin')->setPrenom('Paul');
        $club = (new Club())->setNom("Club Test $id");
        static::getContainer()->get(CreationClub::class)->creer($club, $admin, 'motdepasse-admin');
        $this->clubId = $club->getId();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $equipe = $em->getRepository(Categorie::class)->findOneBy(['club' => $club], ['ordre' => 'ASC']);
        $role = Categorie::SECTEURS[$equipe->getSecteur()]['role'];

        $educ = (new Utilisateur())->setClub($club)->setEmail("educ-$id@exemple.test")->setNom('Coach')->setPrenom('Marc')->setRoles([$role]);
        $chef = (new Utilisateur())->setClub($club)->setEmail("chef-$id@exemple.test")->setNom('Chef')->setPrenom('Luc');
        $equipe->setResponsableMatchs($educ);
        $match = (new Rencontre())->setClub($club)->setCategorie($equipe)->setDate(new \DateTimeImmutable('today'))
            ->setDomicile("CLUB TEST $id")->setExterieur('FC METZ');
        foreach ([$educ, $chef, $match] as $o) {
            $em->persist($o);
        }
        $em->flush();
        $voir = '/matchs/' . $match->getId();

        // Sans droit du secteur, Luc ne voit pas le match.
        $this->client->loginUser($chef);
        $this->client->request('GET', $voir);
        self::assertResponseStatusCodeSame(403);

        // L'administrateur le nomme responsable du secteur, sur la page Catégories : il reçoit le droit du secteur.
        $this->client->loginUser($admin);
        $form = $this->client->request('GET', '/club/categories')->selectButton('Enregistrer les responsables')->form();
        $form['responsable[' . $equipe->getSecteur() . ']'] = (string) $chef->getId();
        $this->client->submit($form);
        self::assertResponseRedirects();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $chef = $em->find(Utilisateur::class, $chef->getId());
        self::assertContains($role, $chef->getRolesAttribues());

        // Il voit et modifie le match (l'équipe a pourtant son éducateur responsable), sa convocation, et choisit qui modifie.
        $this->client->loginUser($chef);
        $this->client->request('GET', $voir);
        self::assertSelectorTextContains('.trace-modif', 'Responsable du secteur');
        foreach (['/modifier', '/convocation'] as $page) {
            $this->client->request('GET', $voir . $page);
            self::assertResponseIsSuccessful();
        }
        $this->client->request('GET', '/matchs/equipe/' . $equipe->getId() . '/responsables');
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('select[name="responsable"]');
    }

    public function testSeulsLeResponsableEtSesDesignesModifient(): void
    {
        $id = bin2hex(random_bytes(4));
        $admin = (new Utilisateur())->setEmail("admin-$id@exemple.test")->setNom('Admin')->setPrenom('Paul');
        $club = (new Club())->setNom("Club Test $id");
        static::getContainer()->get(CreationClub::class)->creer($club, $admin, 'motdepasse-admin');
        $this->clubId = $club->getId();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $equipe = $em->getRepository(Categorie::class)->findOneBy(['club' => $club], ['ordre' => 'ASC']);
        $role = Categorie::SECTEURS[$equipe->getSecteur()]['role'];

        $educ = (new Utilisateur())->setClub($club)->setEmail("educ-$id@exemple.test")->setNom('Coach')->setPrenom('Marc')->setRoles([$role]);
        $adjoint = (new Utilisateur())->setClub($club)->setEmail("adjoint-$id@exemple.test")->setNom('Aide')->setPrenom('Julie')->setRoles([$role]);
        $match = (new Rencontre())->setClub($club)->setCategorie($equipe)->setDate(new \DateTimeImmutable('today'))
            ->setDomicile("CLUB TEST $id")->setExterieur('FC METZ');
        foreach ([$educ, $adjoint, $match] as $o) {
            $em->persist($o);
        }
        $em->flush();
        $voir = '/matchs/' . $match->getId();

        // Pas encore de responsable : tout l'encadrement du secteur modifie (comme avant).
        $this->client->loginUser($adjoint);
        $this->client->request('GET', $voir . '/modifier');
        self::assertResponseIsSuccessful();

        // L'administrateur choisit l'éducateur responsable de l'équipe.
        $this->client->loginUser($admin);
        $crawler = $this->client->request('GET', '/matchs/equipe/' . $equipe->getId() . '/responsables');
        $form = $crawler->selectButton('Enregistrer')->form();
        $form['responsable'] = (string) $educ->getId();
        $this->client->submit($form);
        self::assertResponseRedirects();

        // L'adjoint voit le match, en lecture seule, mais ne peut plus le modifier, ni le supprimer, ni le suivre en direct.
        $this->client->loginUser($adjoint);
        $crawler = $this->client->request('GET', $voir);
        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('a[href$="/modifier"]'));
        self::assertSelectorTextContains('.trace-modif', 'lecture seule');
        self::assertCount(0, $crawler->filter('a[href$="/convocation"]'));
        foreach (['/modifier', '/direct', '/convocation'] as $page) {
            $this->client->request('GET', $voir . $page);
            self::assertResponseStatusCodeSame(403);
        }
        $this->client->request('POST', $voir . '/supprimer', ['_token' => 'x']);
        self::assertResponseStatusCodeSame(403);
        $this->client->request('POST', $voir . '/composition', ['_token' => 'x']);
        self::assertResponseStatusCodeSame(403);
        // Ni choisir lui-même qui modifie.
        $this->client->request('GET', '/matchs/equipe/' . $equipe->getId() . '/responsables');
        self::assertResponseStatusCodeSame(403);

        // L'éducateur responsable modifie le match : la trace de la dernière modification porte son nom.
        $this->client->loginUser($educ);
        $form = $this->client->request('GET', $voir . '/modifier')->selectButton('Enregistrer le match')->form();
        $form['rencontre[lieu]'] = 'Stade municipal';
        $this->client->submit($form);
        self::assertResponseRedirects($voir);
        $this->client->followRedirect();
        self::assertSelectorTextContains('.trace-modif', 'Dernière modification le');
        self::assertSelectorTextContains('.trace-modif', 'Marc');

        // Il désigne l'adjoint, qui peut alors modifier lui aussi.
        $form = $this->client->request('GET', '/matchs/equipe/' . $equipe->getId() . '/responsables')->selectButton('Enregistrer')->form();
        $form['redacteurs'][0]->tick();
        $this->client->submit($form);
        $this->client->loginUser($adjoint);
        $this->client->request('GET', $voir . '/modifier');
        self::assertResponseIsSuccessful();
        $this->client->request('GET', $voir . '/convocation');
        self::assertResponseIsSuccessful();

        // L'administrateur garde toujours la main.
        $this->client->loginUser($admin);
        $this->client->request('GET', $voir . '/modifier');
        self::assertResponseIsSuccessful();
    }
}
