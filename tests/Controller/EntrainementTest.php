<?php

namespace App\Tests\Controller;

use App\Entity\AbsenceEntrainement;
use App\Entity\Categorie;
use App\Entity\Club;
use App\Entity\CreneauEntrainement;
use App\Entity\Entrainement;
use App\Entity\Joueur;
use App\Entity\Presence;
use App\Entity\SemainePresence;
use App\Entity\Utilisateur;
use App\Service\CreationClub;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Planning des entraînements : créneau récurrent, entraînements générés, absences signalées
 * par la famille ou notées par l'éducateur (reportées dans la grille des présences), annulation.
 */
class EntrainementTest extends WebTestCase
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
            // Les grilles de présences et les joueurs référencent les catégories : supprimés avant le club.
            $connexion = $this->em()->getConnection();
            foreach (['presence', 'semaine_presence', 'exercice', 'seance', 'rencontre', 'joueur'] as $table) {
                $connexion->executeStatement("DELETE FROM $table WHERE club_id = ?", [$this->clubId]);
            }
            $connexion->executeStatement('DELETE FROM club WHERE id = ?', [$this->clubId]);
        }
        parent::tearDown();
    }

    public function testPlanningEtAbsences(): void
    {
        $id = bin2hex(random_bytes(4));
        $admin = (new Utilisateur())->setEmail("admin-$id@exemple.test")->setNom('Test')->setPrenom('Coach');
        $club = (new Club())->setNom("Club Test $id");
        static::getContainer()->get(CreationClub::class)->creer($club, $admin, 'motdepasse-admin');
        $this->clubId = $club->getId();
        $em = $this->em();
        $categorie = $em->getRepository(Categorie::class)->findOneBy(['club' => $club], ['ordre' => 'ASC']);
        $lea = $this->joueur($club, $categorie, 'Léa');
        $tom = $this->joueur($club, $categorie, 'Tom');
        $parent = (new Utilisateur())->setClub($club)->setFamille(true)->setEmail("parent-$id@exemple.test")->setNom('Martin')->setPrenom('Famille')->addJoueur($lea);
        $parent->setPassword(static::getContainer()->get(UserPasswordHasherInterface::class)->hashPassword($parent, 'secret-famille'));
        $em->persist($parent);
        $em->flush();

        // --- L'éducateur ajoute le créneau du mercredi 18:00-19:30.
        $this->client->loginUser($admin);
        $crawler = $this->client->request('GET', '/planning?categorie=' . $categorie->getId());
        self::assertResponseIsSuccessful();
        $this->client->submit($crawler->selectButton('+ Ajouter le créneau')->form([
            'jour' => '3', 'heureDebut' => '18:00', 'heureFin' => '19:30', 'lieu' => 'Stade municipal',
        ]));
        $crawler = $this->client->followRedirect();
        self::assertSelectorTextContains('.flash-succes', 'Mercredi 18:00-19:30');
        self::assertGreaterThanOrEqual(3, $crawler->filter('article.entrainement')->count());
        // Période plus longue : jusqu'au 30 juin, un mercredi par semaine.
        $troisSemaines = $crawler->filter('article.entrainement')->count();
        $crawler = $this->client->request('GET', '/planning?categorie=' . $categorie->getId() . '&periode=saison');
        $finSaison = new \DateTimeImmutable(((int) date('Y') + ((int) date('n') >= 7 ? 1 : 0)) . '-06-30');
        $attendus = intdiv((new \DateTimeImmutable('today'))->diff($finSaison)->days, 7);
        self::assertGreaterThanOrEqual(max($troisSemaines, $attendus), $crawler->filter('article.entrainement')->count());
        self::assertSelectorExists('select[name="periode"] option[value="saison"][selected]');

        $mercredis = $this->entrainements($categorie);
        [$premier, $deuxieme] = $mercredis;

        // Séance à préparer depuis le planning : date et catégorie préremplies, puis affichée sur l'entraînement.
        $crawler = $this->client->request('GET', '/planning?categorie=' . $categorie->getId());
        $lien = $crawler->filter('#entrainement-' . $premier->getId())->selectLink('Préparer la séance')->link();
        $crawler = $this->client->click($lien);
        self::assertInputValueSame('seance[date]', $premier->getDate()->format('Y-m-d'));
        $this->client->submit($crawler->filter('form')->last()->form(['seance[theme]' => 'Conservation du ballon']));
        $this->client->request('GET', '/planning?categorie=' . $categorie->getId());
        self::assertSelectorTextContains('#entrainement-' . $premier->getId() . ' .seance-prevue', 'Séance : Conservation du ballon');
        self::assertSelectorTextContains('#entrainement-' . $deuxieme->getId() . ' .seance-prevue', 'Préparer la séance');
        self::assertSame('mer_a', $premier->getCreneauGrille());

        // --- La famille voit les entraînements des 14 prochains jours et signale une absence.
        $this->client->loginUser($parent);
        $crawler = $this->client->request('GET', '/espace');
        self::assertSelectorTextContains('#entrainements-' . $lea->getId(), 'Prochains entraînements');
        self::assertSelectorTextContains('main', '18h00 – 19h30');
        $this->client->submit($crawler->selectButton('Signaler l\'absence')->first()->form(), ['motif' => 'Malade', 'commentaire' => 'Fièvre']);
        $crawler = $this->client->followRedirect();
        self::assertSelectorTextContains('.flash-succes', 'Absence de Léa signalée');
        self::assertSame('Malade', $this->grille($categorie, $premier, 'Léa'));
        self::assertNull($this->grille($categorie, $premier, 'Tom')); // semaine créée avec tout l'effectif

        // Finalement présente : la case de la grille se vide.
        $this->client->submit($crawler->selectButton('Finalement présent(e)')->form());
        self::assertNull($this->grille($categorie, $premier, 'Léa'));
        self::assertCount(0, $this->em()->getRepository(AbsenceEntrainement::class)->findBy(['joueur' => $lea->getId()]));

        // Elle ne peut pas signaler pour Tom.
        $this->client->request('POST', '/espace/entrainement/' . $premier->getId(), ['joueur' => $tom->getId(), 'motif' => 'Malade', '_token' => 'x']);
        self::assertResponseStatusCodeSame(403);

        // --- L'éducateur note Tom absent (scolaire) et annule le deuxième mercredi (famille prévenue par e-mail).
        $this->client->loginUser($admin);
        $crawler = $this->client->request('GET', '/planning?categorie=' . $categorie->getId());
        $article = $crawler->filter('#entrainement-' . $premier->getId());
        $this->client->submit($article->selectButton('Noter')->form(), ['joueur' => (string) $tom->getId(), 'motif' => 'Scolaire']);
        self::assertSame('Excusé', $this->grille($categorie, $premier, 'Tom'));

        $crawler = $this->client->request('GET', '/planning?categorie=' . $categorie->getId());
        $this->client->submit($crawler->filter('#entrainement-' . $deuxieme->getId())->selectButton('Annuler l\'entraînement')->form(), ['motif' => 'Terrain gelé']);
        self::assertEmailCount(1);
        self::assertEmailTextBodyContains(self::getMailerMessage(), 'Motif : Terrain gelé');
        $crawler = $this->client->followRedirect();
        self::assertSelectorTextContains('#entrainement-' . $deuxieme->getId(), 'annulé : Terrain gelé');
        self::assertSelectorExists('#entrainement-' . $deuxieme->getId() . ' a[href^="https://wa.me/"]');

        $this->client->loginUser($parent);
        $this->client->request('GET', '/espace');
        self::assertSelectorTextContains('#entrainements-' . $lea->getId() . ' + p + ul', 'annulé : Terrain gelé');

        // --- Créneau déplacé à 17:00 : le mercredi avec une absence garde sa date, prend le nouvel horaire.
        $this->client->loginUser($admin);
        $crawler = $this->client->request('GET', '/planning?categorie=' . $categorie->getId());
        $this->client->submit($crawler->filter('form.ligne-creneau')->first()->form(), ['heureDebut' => '17:00']);
        $this->client->followRedirect();
        $this->em()->clear();
        $apres = $this->entrainements($categorie);
        self::assertSame(['17:00'], array_values(array_unique(array_map(fn (Entrainement $e) => $e->getHeureDebut(), $apres))));
        self::assertNotNull($this->em()->find(Entrainement::class, $premier->getId()), 'entraînement avec absence conservé');

        // --- Créneau supprimé : plus d'entraînements à venir.
        $crawler = $this->client->request('GET', '/planning?categorie=' . $categorie->getId());
        $this->client->submit($crawler->filter('form[action$="/supprimer"]')->first()->form());
        $this->client->followRedirect();
        self::assertSelectorNotExists('article.entrainement');
        self::assertCount(0, $this->em()->getRepository(CreneauEntrainement::class)->findBy(['categorie' => $categorie->getId()]));
    }

    private function joueur(Club $club, Categorie $categorie, string $prenom): Joueur
    {
        $joueur = (new Joueur())->setClub($club)->setCategorie($categorie)->setNom('Martin')->setPrenom($prenom);
        $this->em()->persist($joueur);

        return $joueur;
    }

    /** @return list<Entrainement> entraînements à venir de la catégorie */
    private function entrainements(Categorie $categorie): array
    {
        return $this->em()->getRepository(Entrainement::class)->findBy(['categorie' => $categorie->getId()], ['date' => 'ASC']);
    }

    /** Valeur de la grille des présences pour ce joueur, au créneau de cet entraînement. */
    private function grille(Categorie $categorie, Entrainement $e, string $prenom): ?string
    {
        $em = $this->em();
        $em->clear();
        $semaine = $em->getRepository(SemainePresence::class)->findOneBy(['categorie' => $categorie->getId(), 'lundi' => $e->getDate()->modify('monday this week')]);
        self::assertNotNull($semaine, 'semaine de présences créée');
        foreach ($semaine->getPresences() as $p) {
            /** @var Presence $p */
            if ($p->getJoueur()->getPrenom() === $prenom) {
                return $p->getCreneau('mer_a');
            }
        }
        self::fail("$prenom absent de la grille.");
    }

    private function em(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }
}
