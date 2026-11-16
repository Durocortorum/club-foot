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

        // Catégorie choisie dans les présences : reprise par le planning (catégorie mémorisée).
        $this->client->loginUser($admin);
        $autreCategorie = $em->getRepository(Categorie::class)->findOneBy(['club' => $club], ['ordre' => 'DESC']);
        $this->client->request('GET', '/presences?categorie=' . $autreCategorie->getId());
        $this->client->request('GET', '/planning');
        self::assertSelectorExists('select[name="categorie"] option[value="' . $autreCategorie->getId() . '"][selected]');

        // --- L'éducateur ajoute le créneau du mercredi 18:00-19:30.
        $crawler = $this->client->request('GET', '/planning?categorie=' . $categorie->getId());
        self::assertResponseIsSuccessful();
        $this->client->submit($crawler->selectButton('+ Ajouter le créneau')->form([
            'jour' => '3', 'heureDebut' => '18:00', 'heureFin' => '19:30', 'lieu' => 'Stade municipal',
        ]));
        $crawler = $this->client->followRedirect();
        self::assertSelectorTextContains('.flash-succes', 'Mercredi 18:00-19:30');
        self::assertGreaterThanOrEqual(3, $crawler->filter('article.entrainement')->count());
        // Période plus longue : jusqu'au 30 juin, un mercredi par semaine.
        $datesEducateur = $crawler->filter('article.entrainement > .avec-actions > div > strong')->each(fn ($n) => $n->text());
        $troisSemaines = \count($datesEducateur);
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
        $this->client->submit($crawler->filter('form[name="seance"]')->form(['seance[theme]' => 'Conservation du ballon']));
        $this->client->request('GET', '/planning?categorie=' . $categorie->getId());
        self::assertSelectorTextContains('#entrainement-' . $premier->getId() . ' .seance-prevue', 'Séance : Conservation du ballon');
        self::assertSelectorTextContains('#entrainement-' . $deuxieme->getId() . ' .seance-prevue', 'Préparer la séance');
        self::assertSame('mer_a', $premier->getCreneauGrille());

        // --- La famille voit les entraînements des 14 prochains jours et signale une absence.
        $this->client->loginUser($parent);
        $crawler = $this->client->request('GET', '/espace');
        self::assertSelectorTextContains('#entrainements-' . $lea->getId(), 'Prochains entraînements');
        self::assertSelectorTextContains('main', '18h00 – 19h30');
        // Mêmes dates que la page de l'éducateur (période par défaut).
        self::assertSame($datesEducateur, $crawler->filter('#entrainements-' . $lea->getId() . ' + p + ul > li > strong')->each(fn ($n) => $n->text()));
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
        // Feuille d'appel : la famille de Léa la signale blessée ; l'éducateur note Tom présent.
        $this->client->loginUser($parent);
        $crawler = $this->client->request('GET', '/espace');
        $this->client->submit($crawler->selectButton('Signaler l\'absence')->first()->form(), ['motif' => 'Blessé', 'commentaire' => 'Cheville']);
        $this->client->loginUser($admin);
        $crawler = $this->client->request('GET', '/planning?categorie=' . $categorie->getId());
        $appel = $crawler->filter('#entrainement-' . $premier->getId() . ' .appel');
        self::assertStringContainsString('Cheville', $appel->text());
        self::assertSame('Blessé', $appel->filter('select[name="choix[' . $lea->getId() . ']"] option[selected]')->attr('value'));
        self::assertSame('', $appel->filter('select[name="choix[' . $tom->getId() . ']"]')->filter('option[selected]')->count() ? 'x' : '');
        $this->client->submit($appel->selectButton('Enregistrer l\'appel')->form(), ['choix[' . $tom->getId() . ']' => 'present']);
        $this->client->followRedirect();
        self::assertSelectorTextContains('.flash-succes', 'Appel enregistré (2 joueurs notés)');
        self::assertSame((string) $categorie, $this->grille($categorie, $premier, 'Tom'));
        self::assertSame('Blessé', $this->grille($categorie, $premier, 'Léa'));

        // Léa finalement venue : notée présente, son absence signalée est retirée.
        $crawler = $this->client->request('GET', '/planning?categorie=' . $categorie->getId());
        $appel = $crawler->filter('#entrainement-' . $premier->getId() . ' .appel');
        self::assertSame('present', $appel->filter('select[name="choix[' . $tom->getId() . ']"] option[selected]')->attr('value'));
        $this->client->submit($appel->selectButton('Enregistrer l\'appel')->form(), ['choix[' . $lea->getId() . ']' => 'present']);
        self::assertSame((string) $categorie, $this->grille($categorie, $premier, 'Léa'));
        self::assertCount(0, $this->em()->getRepository(AbsenceEntrainement::class)->findBy(['joueur' => $lea->getId()]));

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

        // Rétabli : la famille est prévenue que l'entraînement a bien lieu.
        $this->client->loginUser($admin);
        $crawler = $this->client->request('GET', '/planning?categorie=' . $categorie->getId());
        $this->client->submit($crawler->filter('#entrainement-' . $deuxieme->getId())->selectButton('Rétablir')->form());
        self::assertEmailCount(1);
        self::assertEmailHeaderSame(self::getMailerMessage(), 'subject', "Club Test $id : entraînement " . mb_strtolower(['Dimanche', 'Lundi', 'Mardi', 'Mercredi', 'Jeudi', 'Vendredi', 'Samedi'][(int) $deuxieme->getDate()->format('w')]) . ' ' . $deuxieme->getDate()->format('d/m') . ' maintenu');
        $this->client->followRedirect();
        self::assertSelectorTextContains('.flash-succes', '1 famille prévenue');
        $crawler = $this->client->request('GET', '/planning?categorie=' . $categorie->getId());
        $this->client->submit($crawler->filter('#entrainement-' . $deuxieme->getId())->selectButton('Annuler l\'entraînement')->form(), ['motif' => 'Terrain gelé']);

        // --- Créneau déplacé à 17:00 : le mercredi annulé est conservé (avec son annulation), au nouvel horaire.
        $this->client->loginUser($admin);
        $crawler = $this->client->request('GET', '/planning?categorie=' . $categorie->getId());
        $this->client->submit($crawler->filter('form.ligne-creneau')->first()->form(), ['heureDebut' => '17:00']);
        $this->client->followRedirect();
        $this->em()->clear();
        $apres = $this->entrainements($categorie);
        self::assertSame(['17:00'], array_values(array_unique(array_map(fn (Entrainement $e) => $e->getHeureDebut(), $apres))));
        self::assertTrue($this->em()->find(Entrainement::class, $deuxieme->getId())?->isAnnule(), 'entraînement annulé conservé');
        self::assertSame((string) $categorie, $this->grille($categorie, $premier, 'Tom'), 'l\'appel reste dans la grille');

        // --- Un seul entraînement supprimé (sans prévenir) : il disparaît, son absence quitte la grille,
        // et le planning ne le recrée pas.
        $autre = $this->em()->find(Entrainement::class, $apres[\count($apres) - 2]->getId());
        static::getContainer()->get(\App\Service\PlanningEntrainements::class)
            ->signalerAbsence($autre, $this->em()->find(Joueur::class, $tom->getId()), 'Malade', null, 'test');
        $crawler = $this->client->request('GET', '/planning?categorie=' . $categorie->getId());
        $avant = $crawler->filter('article.entrainement')->count();
        $this->client->submit($crawler->filter('form[action$="/entrainement/' . $autre->getId() . '/supprimer"]')->form());
        $this->client->followRedirect();
        self::assertSelectorTextContains('.flash-succes', 'supprimé');
        $this->em()->clear();
        $autre = $this->em()->find(Entrainement::class, $autre->getId());
        self::assertTrue($autre->isSupprime());
        self::assertCount(0, $autre->getAbsences());
        $crawler = $this->client->request('GET', '/planning?categorie=' . $categorie->getId());
        self::assertSame($avant - 1, $crawler->filter('article.entrainement')->count(), 'pas recréé');
        $semaineAutre = $this->em()->getRepository(SemainePresence::class)->findOneBy(['categorie' => $categorie->getId(), 'lundi' => $autre->getDate()->modify('monday this week')]);
        self::assertTrue(null === $semaineAutre || !array_filter(array_map(fn (Presence $p) => $p->getCreneau('mer_a'), $semaineAutre->getPresences()->toArray())), 'absence retirée de la grille');
        $apres = array_values(array_filter($apres, fn (Entrainement $e) => $e->getId() !== $autre->getId()));

        // Absence signalée sur un entraînement d'une autre semaine : reportée dans la grille de cette semaine.
        $dernier = $this->em()->find(Entrainement::class, end($apres)->getId());
        $lundiDernier = $dernier->getDate()->modify('monday this week');
        static::getContainer()->get(\App\Service\PlanningEntrainements::class)
            ->signalerAbsence($dernier, $this->em()->find(Joueur::class, $tom->getId()), 'Malade', null, 'test');
        self::assertSame('Malade', $this->grille($categorie, $dernier, 'Tom'));

        // --- Créneau supprimé : plus d'entraînements à venir ; l'absence reportée quitte la grille,
        // et la semaine, devenue vide, disparaît ; l'appel déjà fait reste (historique).
        $crawler = $this->client->request('GET', '/planning?categorie=' . $categorie->getId());
        $this->client->submit($crawler->filter('form[action$="/supprimer"]')->first()->form());
        $this->client->followRedirect();
        self::assertSelectorNotExists('article.entrainement');
        self::assertCount(0, $this->em()->getRepository(CreneauEntrainement::class)->findBy(['categorie' => $categorie->getId()]));
        $this->em()->clear();
        self::assertNull($this->em()->getRepository(SemainePresence::class)->findOneBy(['categorie' => $categorie->getId(), 'lundi' => $lundiDernier]), 'semaine vide supprimée');
        self::assertSame((string) $categorie, $this->grille($categorie, $premier, 'Tom'), 'l\'appel reste dans la grille');
    }

    public function testBibliothequeGenerale(): void
    {
        $id = bin2hex(random_bytes(4));
        $admin = (new Utilisateur())->setEmail("admin-$id@exemple.test")->setNom('Test')->setPrenom('Coach');
        $club = (new Club())->setNom("Club Test $id");
        static::getContainer()->get(CreationClub::class)->creer($club, $admin, 'motdepasse-admin');
        $this->clubId = $club->getId();
        $em = $this->em();
        $categorie = $em->getRepository(Categorie::class)->findOneBy(['club' => $club], ['ordre' => 'ASC']);
        $seance = (new \App\Entity\Seance())->setClub($club)->setDate(new \DateTimeImmutable('today'))->setCategorie($categorie)->setTheme('Jeu court');
        $em->persist($seance);
        $em->flush();
        $bibliotheque = static::getContainer()->get(\App\Service\BibliothequeExercices::class);
        $tous = $bibliotheque->tous();
        $cle = array_key_first($tous);
        $titre = $tous[$cle]['titre'];

        // Depuis la liste des séances : tous les exercices types, sans passer par une séance.
        $this->client->loginUser($admin);
        $crawler = $this->client->request('GET', '/entrainements');
        $crawler = $this->client->click($crawler->selectLink('Bibliothèque d\'exercices')->link());
        self::assertResponseIsSuccessful();
        self::assertCount(\count($tous), $crawler->filter('article.exercice-type'));

        // Recherche par titre (sans accents ni majuscules) et filtre par groupe.
        $crawler = $this->client->request('GET', '/entrainements/bibliotheque?q=' . urlencode(strtoupper(strtr($titre, ['é' => 'e', 'è' => 'e', 'à' => 'a']))));
        self::assertStringContainsString($titre, $crawler->filter('article.exercice-type')->first()->text());
        $crawler = $this->client->request('GET', '/entrainements/bibliotheque?groupe=seniors');
        self::assertCount(\count(array_filter($tous, fn ($e) => 'seniors' === $e['secteur'])), $crawler->filter('article.exercice-type'));
        self::assertSelectorCount(1, 'section.bloc');

        // Ajout à une séance choisie dans la liste.
        $crawler = $this->client->request('GET', '/entrainements/bibliotheque');
        $this->client->submit($crawler->filter('form[action$="/bibliotheque/' . $cle . '"]')->form(), ['seance' => $seance->getId()]);
        $this->client->followRedirect();
        self::assertSelectorTextContains('.flash-succes', '« ' . $titre . ' » ajouté à la séance');
        $em->clear();
        self::assertSame([$titre], array_map(fn ($e) => $e->getTitre(), $em->find(\App\Entity\Seance::class, $seance->getId())->getExercices()->toArray()));

        // Exercice animé : les étapes suivent l'exercice copié ; places invalides et objets écartés retirés.
        $anime = $bibliotheque->creer('passes_y');
        self::assertCount(3, $anime->getSchema()['etapes']);
        self::assertSame([22.4, 22.2], $anime->copier()->getSchema()['etapes'][0][9]);
        $schema = (new \App\Entity\Exercice())->setSchema([
            'objets' => [['t' => 'joueur', 'x' => 1, 'y' => 1], 'pas un objet', ['t' => 'ballon', 'x' => 2, 'y' => 2]],
            'etapes' => [[[5, 6], [7, 8], ['x', 1]], 'pas une étape'],
        ])->getSchema();
        self::assertSame([[[5.0, 6.0], null]], $schema['etapes']);
        self::assertArrayNotHasKey('etapes', (new \App\Entity\Exercice())->setSchema(['objets' => []])->getSchema());
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
