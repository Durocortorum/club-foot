<?php

namespace App\Command;

use App\Entity\ActionMatch;
use App\Entity\Administratif;
use App\Entity\Arbitre;
use App\Entity\ArticleBoutique;
use App\Entity\Benevole;
use App\Entity\Blessure;
use App\Entity\But;
use App\Entity\Categorie;
use App\Entity\ResultatPoule;
use App\Entity\Club;
use App\Entity\CommandeBoutique;
use App\Entity\Conducteur;
use App\Entity\ContactUtile;
use App\Entity\Contrat;
use App\Entity\Convocation;
use App\Entity\CombinaisonCpa;
use App\Entity\Convoque;
use App\Entity\Cotisation;
use App\Entity\CreneauEntrainement;
use App\Entity\Deplacement;
use App\Entity\Dirigeant;
use App\Entity\Educateur;
use App\Entity\EtatForme;
use App\Entity\Evenement;
use App\Entity\FicheAdversaire;
use App\Entity\Joueur;
use App\Entity\Message;
use App\Entity\ObjectifJoueur;
use App\Entity\ObservationJoueur;
use App\Entity\Operation;
use App\Entity\Paiement;
use App\Entity\PartenariatSponsor;
use App\Entity\Participation;
use App\Entity\PosteOrganigramme;
use App\Entity\Presence;
use App\Entity\Rencontre;
use App\Entity\Ressenti;
use App\Entity\ResultatTest;
use App\Entity\Sanction;
use App\Entity\SemainePresence;
use App\Entity\Sponsor;
use App\Entity\Stage;
use App\Entity\TableauTactique;
use App\Entity\TarifCotisation;
use App\Entity\Tache;
use App\Entity\Tournoi;
use App\Entity\Utilisateur;
use App\Entity\Vehicule;
use App\Service\CatalogueTests;
use App\Service\ClubCourant;
use App\Service\CreationClub;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Crée un club de démonstration entièrement inventé (« FC Démo des Coteaux »), pour présenter l'application :
 * joueurs, encadrement, matchs avec feuilles et buts, convocation, entraînements, calendrier, finances, boutique,
 * infirmerie, tests, discipline, transport, sponsors… Aucune donnée réelle ; aucun e-mail ni notification envoyé.
 * Les adresses e-mail sont en @demo.org, facile à retenir ; ce domaine existe ailleurs : aucun e-mail n'y part (EmailsDemoBloques).
 *
 *   php bin/console app:club-demo                  crée le club (refuse s'il existe déjà)
 *   php bin/console app:club-demo --reinitialiser  le supprime et le recrée (dates remises à jour, mots de passe conservés)
 *                                                  lancé chaque nuit sur le serveur (tâche planifiée) et par le bouton
 *                                                  « Remettre la démo à zéro » (DemoController)
 *   php bin/console app:club-demo --supprimer      le supprime
 */
#[AsCommand(name: 'app:club-demo', description: 'Crée un club de démonstration aux données inventées')]
class ClubDemoCommand extends Command
{
    public const NOM = 'FC Démo des Coteaux';
    public const DOMAINE = 'demo.org';
    private const NOM_EQUIPE = 'FC DÉMO DES COTEAUX';

    private const PRENOMS = ['Lucas', 'Hugo', 'Nathan', 'Léo', 'Tom', 'Enzo', 'Louis', 'Gabriel', 'Raphaël', 'Arthur', 'Jules', 'Adam', 'Noah', 'Maël', 'Sacha',
        'Ethan', 'Paul', 'Mathis', 'Nolan', 'Timéo', 'Liam', 'Axel', 'Yanis', 'Rayan', 'Ilyes', 'Bastien', 'Robin', 'Théo', 'Evan', 'Kylian', 'Mohamed', 'Samuel'];
    private const NOMS = ['Martin', 'Bernard', 'Dubois', 'Thomas', 'Robert', 'Richard', 'Petit', 'Durand', 'Leroy', 'Moreau', 'Simon', 'Laurent', 'Lefebvre',
        'Michel', 'Garcia', 'David', 'Bertrand', 'Roux', 'Vincent', 'Fournier', 'Morel', 'Girard', 'André', 'Mercier', 'Dupont', 'Lambert', 'Bonnet', 'François',
        'Martinez', 'Legrand', 'Garnier', 'Faure', 'Rousseau', 'Blanc', 'Guerin', 'Muller', 'Henry', 'Roussel', 'Nicolas', 'Perrin', 'Morin', 'Mathieu', 'Diallo', 'Benali'];
    private const ADVERSAIRES = ['US VALLÉE VERTE', 'AS DES TROIS RIVIÈRES', 'FC BORDS DE LOIRE', 'ES PLATEAU NORD', 'SC LES MOULINS', 'JS DU CANAL',
        'OLYMPIQUE LA CROIX', 'ENTENTE DES VIGNES', 'AS MONTVERT', 'FC SAINT-LAURENT', 'US LA FORÊT', 'STADE DES PRÉS'];
    private const VILLES = ['Les Coteaux', 'Saint-Pierre', 'Montvert', 'La Croix', 'Les Moulins'];

    /** Effectif par catégorie. */
    private const EFFECTIFS = ['U7' => 8, 'U9' => 10, 'U11' => 12, 'U13' => 14, 'U15' => 16, 'U17' => 15, 'U19' => 15, 'SENIORS' => 18, 'VÉTÉRANS' => 12];
    /** Catégories avec matchs et feuilles de match. */
    private const AVEC_MATCHS = ['U13', 'U15', 'U17', 'SENIORS'];

    private Club $club;
    /** @var array<string, Categorie> */
    private array $categories = [];
    /** @var array<string, list<Joueur>> */
    private array $joueurs = [];
    private int $licence = 2400100000;
    private string $saison;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly CreationClub $creation,
        private readonly ClubCourant $clubCourant,
        private readonly UserPasswordHasherInterface $hasher,
        private readonly CatalogueTests $catalogue,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('reinitialiser', null, InputOption::VALUE_NONE, 'Supprime le club de démonstration existant puis le recrée')
            ->addOption('supprimer', null, InputOption::VALUE_NONE, 'Supprime le club de démonstration, sans le recréer')
            ->addOption('mot-de-passe', null, InputOption::VALUE_REQUIRED, 'Mot de passe des comptes de démonstration (sinon un mot de passe est tiré au hasard)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        mt_srand(2026);
        $existant = $this->em->getRepository(Club::class)->findOneBy(['nom' => self::NOM]);
        if ($input->getOption('supprimer')) {
            if ($existant) {
                $this->supprimer($existant);
            }
            $io->success($existant ? 'Club de démonstration supprimé.' : 'Aucun club de démonstration à supprimer.');

            return Command::SUCCESS;
        }
        if ($existant) {
            if (!$input->getOption('reinitialiser')) {
                $io->error('Le club de démonstration existe déjà. Ajoutez --reinitialiser pour le recréer.');

                return Command::FAILURE;
            }
            // Sans --mot-de-passe, les comptes recréés gardent leur mot de passe actuel (remise à zéro de la nuit, bouton).
            $anciensMotsDePasse = $input->getOption('mot-de-passe') ? [] : $this->em->getConnection()->fetchAllKeyValue(
                'SELECT email, password FROM utilisateur WHERE email LIKE ? AND password IS NOT NULL', ['%@' . self::DOMAINE]);
            $this->supprimer($existant);
            $io->text('Ancien club de démonstration supprimé.');
        }
        $this->clubCourant->desactiver();
        $this->categories = $this->joueurs = [];
        $this->licence = 2400100000;
        $motDePasse = $input->getOption('mot-de-passe') ?: 'Demo-' . bin2hex(random_bytes(4));
        $this->saison = Rencontre::saisonDe(new \DateTimeImmutable('today'));

        $io->text('Club, catégories et comptes…');
        $president = (new Utilisateur())->setEmail('president@' . self::DOMAINE)->setPrenom('Jean')->setNom('Morel');
        $this->club = (new Club())->setNom(self::NOM)->setAdresse('Stade des Coteaux, 12 allée des Sports, 49000 Les Coteaux')
            ->setTelephone('02 41 00 00 00')->setEmailContact('contact@' . self::DOMAINE);
        $this->creation->creer($this->club, $president, $motDePasse);
        $this->clubCourant->activer($this->club);
        foreach ($this->em->getRepository(Categorie::class)->findBy(['club' => $this->club]) as $c) {
            $this->categories[$c->getNom()] = $c;
        }

        $this->joueurs();
        [$educateurs, $comptes] = $this->encadrement($motDePasse);
        $io->text('Matchs, buts, convocation et déplacement…');
        $matchs = $this->matchs($educateurs);
        $this->entrainements();
        $io->text('Calendrier, stages, tournoi, événements…');
        $this->vieDuClub();
        $io->text('Finances, sponsors, boutique…');
        $this->finances();
        $this->boutique($comptes['parent']);
        $io->text('Infirmerie, tests, effort ressenti, discipline, tâches, messages…');
        $this->suiviSportif($matchs);
        $this->objectifsIndividuels();
        $this->semainesEntrainement();
        $this->etatsDeForme();
        $this->bibliothequeCpa();
        $this->actionsEnDirect($matchs);
        $this->organisation($comptes);
        $this->em->flush();
        if (!empty($anciensMotsDePasse)) {
            // Un compte ajouté depuis (ex. joueur@) prend le mot de passe des autres comptes de démonstration.
            $commun = $anciensMotsDePasse['president@' . self::DOMAINE] ?? reset($anciensMotsDePasse);
            foreach (['president', 'educateur', 'parent', 'joueur'] as $qui) {
                $email = $qui . '@' . self::DOMAINE;
                $this->em->getConnection()->executeStatement('UPDATE utilisateur SET password = ? WHERE email = ?', [$anciensMotsDePasse[$email] ?? $commun, $email]);
            }
            $motDePasse = '(inchangé)';
            // Comptes en mémoire avec le mot de passe provisoire : relus depuis la base (sinon le bouton déconnecte).
            $this->em->clear();
        }

        $io->success('Club de démonstration « ' . self::NOM . ' » créé.');
        $io->table(['Rôle', 'Identifiant', 'Mot de passe'], [
            ['Président (administrateur)', 'president@' . self::DOMAINE, $motDePasse],
            ['Éducateur U15 (préformation)', 'educateur@' . self::DOMAINE, $motDePasse],
            ['Parent (2 enfants : U15 et U11)', 'parent@' . self::DOMAINE, $motDePasse],
            ['Joueur senior (majeur)', 'joueur@' . self::DOMAINE, $motDePasse],
        ]);
        $io->note('Données inventées. Les adresses @' . self::DOMAINE . ' ne reçoivent jamais d\'e-mail.');

        return Command::SUCCESS;
    }

    // ———————————————————————— Joueurs et encadrement ————————————————————————

    private function joueurs(): void
    {
        $finSaison = (int) substr($this->saison, 5, 4);
        $postes = ['Gardien', 'Défenseur', 'Défenseur', 'Latéral droit', 'Latéral gauche', 'Milieu', 'Milieu', 'Milieu offensif droit', 'Milieu offensif gauche', 'Attaquant', 'Attaquant'];
        $n = 0;
        foreach (self::EFFECTIFS as $nom => $effectif) {
            $categorie = $this->categories[$nom] ?? null;
            if (!$categorie) {
                continue;
            }
            $age = match ($nom) { 'SENIORS' => 24, 'VÉTÉRANS' => 42, default => (int) substr($nom, 1) };
            for ($i = 0; $i < $effectif; ++$i, ++$n) {
                $annee = $finSaison - $age - ($i % 2);
                $mineur = $age < 18;
                $prenom = self::PRENOMS[($n * 7 + $i) % \count(self::PRENOMS)];
                $famille = self::NOMS[($n * 5 + 3) % \count(self::NOMS)];
                $j = (new Joueur())->setCategorie($categorie)->setNom($famille)->setPrenom($prenom)
                    ->setDateNaissance(new \DateTimeImmutable(\sprintf('%d-%02d-%02d', $annee, 1 + ($n * 5) % 12, 1 + ($n * 11) % 27)))
                    ->setPoste($postes[$i % \count($postes)])->setPied(0 === $i % 5 ? 'Gaucher' : 'Droitier')
                    ->setLicence((string) $this->licence++)->setDateArrivee(new \DateTimeImmutable((2026 - $i % 6) . '-08-25'))
                    ->setAdresse((3 + $n) . ' rue des Vignes')->setCodePostal('49000')->setVille(self::VILLES[$n % \count(self::VILLES)])
                    ->setDroitImage(0 !== $i % 7)->setTransportEducateur(0 === $i % 3);
                if (0 === $i % 4 && 'U7' !== $nom) {
                    $j->setAncienClub(self::ADVERSAIRES[$n % \count(self::ADVERSAIRES)]);
                }
                if ($mineur) {
                    $j->setNomParent1('Mme ' . $famille)->setTelPortable1(\sprintf('06 %02d %02d %02d %02d', 10 + $n % 80, $n % 99, ($n * 3) % 99, ($n * 7) % 99))
                        ->setEmailParent1(\sprintf('famille.%s%d@%s', $this->ascii($famille), $n, self::DOMAINE))->setTransportParent(true)
                        ->setAutorisationSoins(0 !== $i % 6)->setPartirSeul($age >= 13 && 0 === $i % 2);
                } else {
                    $j->setTelJoueur(\sprintf('07 %02d %02d %02d %02d', 10 + $n % 80, $n % 99, ($n * 3) % 99, ($n * 7) % 99))
                        ->setEmailJoueur(\sprintf('%s.%s%d@%s', $this->ascii($prenom), $this->ascii($famille), $n, self::DOMAINE));
                }
                $this->em->persist($j);
                $this->joueurs[$nom][] = $j;
            }
        }
    }

    /** @return array{0: array<string, Educateur>, 1: array<string, Utilisateur>} */
    private function encadrement(string $motDePasse): array
    {
        $educateurs = [];
        foreach (['U9' => ['Sylvie', 'Garnier', 'CFF1'], 'U11' => ['Marc', 'Rousseau', 'CFF1'], 'U13' => ['Thomas', 'Henry', 'CFF2'], 'U15' => ['Karim', 'Benali', 'BMF'],
            'U17' => ['Julien', 'Perrin', 'BEF'], 'U19' => ['Antoine', 'Faure', 'BEF'], 'SENIORS' => ['Patrick', 'Lambert', 'BEF']] as $cat => [$prenom, $nom, $diplome]) {
            $e = (new Educateur())->setNom($nom)->setPrenom($prenom)->setFonction('Responsable ' . $cat)->setDiplome($diplome)
                ->setDateDiplome(new \DateTimeImmutable('2019-06-15'))->setPermis('B')->setTelPortable1('06 20 30 ' . (40 + \count($educateurs)) . ' 50')
                ->setEmail(\sprintf('%s.%s@%s', $this->ascii($prenom), $this->ascii($nom), self::DOMAINE));
            $e->addEquipe($this->categories[$cat]);
            // Karim a un compte de démo : même adresse sur sa fiche et pour se connecter.
            if ('U15' === $cat) {
                $e->setEmail('educateur@' . self::DOMAINE);
            }
            $this->em->persist($e);
            $educateurs[$cat] = $e;
            foreach ($this->joueurs[$cat] ?? [] as $j) {
                $j->setEducateur($e);
            }
        }
        $dirigeants = [];
        foreach ([['Jean', 'Morel', 'Président'], ['Nadia', 'Girard', 'Trésorière'], ['Bernard', 'Mercier', 'Secrétaire'], ['Claire', 'Vincent', 'Vice-présidente']] as [$prenom, $nom, $fonction]) {
            $d = (new Dirigeant())->setNom($nom)->setPrenom($prenom)->setFonction($fonction)->setTelPortable1('06 11 22 3' . \count($dirigeants) . ' 44')
                // Le président a un compte de démo : même adresse sur sa fiche et pour se connecter.
                ->setEmail('Président' === $fonction ? 'president@' . self::DOMAINE : \sprintf('%s.%s@%s', $this->ascii($prenom), $this->ascii($nom), self::DOMAINE));
            $this->em->persist($d);
            $dirigeants[$fonction] = $d;
        }
        $this->em->persist((new Administratif())->setNom('Roux')->setPrenom('Émilie')->setFonction('Secrétariat, licences')->setTelPortable1('06 44 55 66 77'));
        foreach ([['Gérard', 'Blanc', 'Buvette'], ['Martine', 'Faure', 'Lavage des maillots'], ['Yves', 'Morin', 'Traçage des terrains']] as [$prenom, $nom, $fonction]) {
            $this->em->persist((new Benevole())->setNom($nom)->setPrenom($prenom)->setFonction($fonction));
        }
        $this->em->persist((new Arbitre())->setNom('Nicolas')->setPrenom('Kevin')->setNiveau('District')->setTelPortable1('06 77 88 99 00'));
        $this->em->flush();

        // Organigramme.
        $postes = [];
        foreach ([['Président', null, 'dirigeants', $dirigeants['Président']], ['Vice-présidente', 'Président', 'dirigeants', $dirigeants['Vice-présidente']],
            ['Trésorière', 'Président', 'dirigeants', $dirigeants['Trésorière']], ['Secrétaire', 'Président', 'dirigeants', $dirigeants['Secrétaire']],
            ['Responsable technique', 'Président', 'educateurs', $educateurs['SENIORS']], ['Responsable école de foot', 'Responsable technique', 'educateurs', $educateurs['U11']],
            ['Responsable préformation', 'Responsable technique', 'educateurs', $educateurs['U15']]] as $i => [$titre, $parent, $type, $fiche]) {
            $p = (new PosteOrganigramme())->setTitre($titre)->setOrdre($i)->setPersonne($type . ':' . $fiche->getId());
            if ($parent) {
                $p->setParent($postes[$parent]);
            }
            $this->em->persist($p);
            $postes[$titre] = $p;
        }
        // Numéros utiles.
        foreach ([['installations', 'Stade des Coteaux – gardien', 'M. Lucas', '02 41 00 11 22', 'Portail : code 4512'], ['instances', 'District de football', 'Secrétariat', '02 41 00 33 44', null],
            ['collectivites', 'Mairie – service des sports', null, '02 41 00 55 66', 'Du lundi au vendredi, 9 h – 17 h'], ['prestataires', 'Expert-comptable', 'Cabinet Compta Plus', '02 41 00 77 88', null],
            ['transport', 'Autocars des Coteaux', null, '02 41 00 99 00', 'Réserver 15 jours avant'], ['urgences', 'Hôpital – urgences', null, '02 41 35 35 35', null]] as [$rubrique, $libelle, $nom, $tel, $note]) {
            $this->em->persist((new ContactUtile())->setRubrique($rubrique)->setLibelle($libelle)->setNom($nom)->setTelephone($tel)->setNote($note));
        }

        // Comptes : éducateur U15, et un parent de deux enfants (U15 et U11).
        $educ = (new Utilisateur())->setEmail('educateur@' . self::DOMAINE)->setPrenom('Karim')->setNom('Benali')
            ->setRolesAttribues(['ROLE_PREFORMATION', 'ROLE_DISCIPLINE', 'ROLE_TESTS_PHYSIQUES', 'ROLE_SCOLAIRE_LECTURE', 'ROLE_FINANCES_LECTURE']);
        $educ->setPassword($this->hasher->hashPassword($educ, $motDePasse));
        $this->em->persist($educ);
        $enfant1 = $this->joueurs['U15'][2];
        $enfant2 = $this->joueurs['U11'][3];
        $enfant2->setNom($enfant1->getNom())->setNomParent1('Sophie ' . $enfant1->getNom());
        foreach ([$enfant1, $enfant2] as $j) {
            $j->setEmailParent1('parent@' . self::DOMAINE);
        }
        $parent = (new Utilisateur())->setFamille(true)->setEmail('parent@' . self::DOMAINE)->setPrenom('Sophie')->setNom($enfant1->getNom())
            ->addJoueur($enfant1)->addJoueur($enfant2);
        $parent->setPassword($this->hasher->hashPassword($parent, $motDePasse));
        $this->em->persist($parent);
        // Compte joueur : un senior (majeur), qui suit sa propre fiche et décide de l'accès de ses proches.
        $senior = $this->joueurs['SENIORS'][8];
        $senior->setEmailJoueur('joueur@' . self::DOMAINE);
        $joueur = (new Utilisateur())->setFamille(true)->setEmail('joueur@' . self::DOMAINE)->setPrenom($senior->getPrenom())->setNom($senior->getNom())
            ->addJoueur($senior);
        $joueur->setPassword($this->hasher->hashPassword($joueur, $motDePasse));
        $this->em->persist($joueur);
        $this->em->flush();

        return [$educateurs, ['educateur' => $educ, 'parent' => $parent, 'joueur' => $joueur, 'president' => $this->em->getRepository(Utilisateur::class)->findOneBy(['email' => 'president@' . self::DOMAINE])]];
    }

    // ———————————————————————— Matchs ————————————————————————

    /**
     * @param array<string, Educateur> $educateurs
     *
     * @return array<string, list<Rencontre>> matchs joués par catégorie
     */
    private function matchs(array $educateurs): array
    {
        // Un match par week-end : 9 joués (tout match passé a son score), puis ceux à venir.
        $aujourdhui = new \DateTimeImmutable('today');
        $samedi = new \DateTimeImmutable('next saturday');
        $joues = [];
        foreach (self::AVEC_MATCHS as $k => $cat) {
            $categorie = $this->categories[$cat];
            $effectif = $this->joueurs[$cat];
            $convoque = false;
            for ($i = 9; $i >= -2; --$i) {
                $date = $samedi->modify(\sprintf('%+d weeks', -$i))->modify('SENIORS' === $cat ? '+1 day' : '+0 day');
                $adversaire = self::ADVERSAIRES[($i + $k * 3 + 12) % \count(self::ADVERSAIRES)];
                $domicile = 0 === ($i + $k) % 2;
                $r = (new Rencontre())->setCategorie($categorie)->setDate($date)->setHeure('SENIORS' === $cat ? '15h00' : ('U13' === $cat ? '10h30' : '14h00'))
                    ->setCompetition(0 === $i % 5 ? 'Coupe' : 'Championnat')
                    ->setDomicile($domicile ? self::NOM_EQUIPE : $adversaire)->setExterieur($domicile ? $adversaire : self::NOM_EQUIPE)
                    ->setLieu($domicile ? 'Stade des Coteaux' : 'Stade de ' . ucwords(mb_strtolower(explode(' ', $adversaire, 2)[1])));
                if ($date < $aujourdhui) {
                    [$pour, $contre] = [mt_rand(0, 4), mt_rand(0, 3)];
                    $r->setButsDomicile($domicile ? $pour : $contre)->setButsExterieur($domicile ? $contre : $pour)
                        ->setButsDomicileMiTemps(intdiv($domicile ? $pour : $contre, 2))->setButsExterieurMiTemps(intdiv($domicile ? $contre : $pour, 2))
                        ->setTerrain(0 === $i % 3 ? 'Synthétique' : 'Herbe')->setMeteo(['Ensoleillé', 'Nuageux', 'Pluie'][$i % 3])->setSysteme('4 3 3')
                        ->setNote(round(mt_rand(50, 80) / 10, 1))->setRapport('Match engagé. Bonne entame, quelques pertes de balle au milieu en deuxième mi-temps.');
                    $this->feuille($r, $effectif, $pour, $contre);
                    $joues[$cat][] = $r;
                }
                $this->em->persist($r);
                // Prochain match des U15, à l'extérieur : convocation envoyée, réponses, déplacement en car.
                if ('U15' === $cat && !$convoque && $date > $aujourdhui) {
                    $convoque = true;
                    $r->setDomicile($adversaire)->setExterieur(self::NOM_EQUIPE)->setLieu('Stade de la Croix');
                    $this->numeroter($r, \array_slice($effectif, 0, 14));
                    $convocation = (new Convocation($r))->setJoueurs(\array_slice($effectif, 0, 14))->setRdvHeure('12h45')->setRdvLieu('Parking du stade des Coteaux')
                        ->setMessage('Prévoir la tenue complète et une gourde. Retour vers 18 h.');
                    foreach ($convocation->getConvoques() as $n => $c) {
                        if ($n < 9) {
                            $c->repondre(Convoque::PRESENT, null, 'Parent');
                        } elseif ($n < 11) {
                            $c->repondre(Convoque::ABSENT, 'Malade', 'Parent');
                        }
                    }
                    $convocation->marquerEnvoyee();
                    $this->em->persist($convocation);
                    $this->em->persist((new Deplacement())->setDate($date)->setCategorie($categorie)->setRencontre($r)->setObjet('Match U15 à La Croix')
                        ->setMoyen('Car de société')->setHeureDepart('13h00')->setLieuDepart('parking du stade des Coteaux')->setPassagers(17)->setFrais(280));
                }
                // Prochain match des seniors : convocation envoyée ; le compte joueur (seniors n° 9) n'a pas encore répondu.
                if ('SENIORS' === $cat && !$convoque && $date > $aujourdhui) {
                    $convoque = true;
                    $this->numeroter($r, \array_slice($effectif, 0, 16));
                    $convocation = (new Convocation($r))->setJoueurs(\array_slice($effectif, 0, 16))->setRdvHeure('14h00')->setRdvLieu('Vestiaires du stade')
                        ->setMessage('Rendez-vous aux vestiaires, échauffement à 14 h 20.');
                    foreach ($convocation->getConvoques() as $n => $c) {
                        if ($n < 6) {
                            $c->repondre(Convoque::PRESENT, null, 'Joueur');
                        } elseif (6 === $n) {
                            $c->repondre(Convoque::ABSENT, 'Travail', 'Joueur');
                        }
                    }
                    $convocation->marquerEnvoyee();
                    $this->em->persist($convocation);
                }
            }
        }
        $this->saisonComplete();
        $this->plateaux();
        $this->em->flush();

        return $joues;
    }

    /**
     * École de foot (U9, U11) : un plateau tous les quinze jours, chez nous ou chez un autre club, avec 3 ou 4 petits
     * matchs notés pour mémoire (pas de classement) ; prochain plateau des U11 : convocation envoyée, réponses en cours.
     */
    private function plateaux(): void
    {
        $aujourdhui = new \DateTimeImmutable('today');
        $debut = (new \DateTimeImmutable(substr($this->saison, 0, 4) . '-09-11'))->modify('next saturday');
        foreach (['U9' => 0, 'U11' => 1] as $cat => $decalage) {
            $convoque = false;
            for ($n = 0; $n < 16; ++$n) {
                // Tous les quinze jours, trêve de Noël comprise (pas de plateau fin décembre).
                $date = $debut->modify(\sprintf('+%d weeks', 2 * $n + $decalage + ($n >= 7 ? 3 : 0)));
                $chezNous = 0 === $n % 3;
                $organisateur = $chezNous ? self::NOM_EQUIPE : self::ADVERSAIRES[($n * 5 + $decalage) % \count(self::ADVERSAIRES)];
                $invites = array_values(array_filter(array_map(fn (int $k) => self::ADVERSAIRES[($n * 5 + $decalage + $k) % \count(self::ADVERSAIRES)], [1, 2, 3]), fn (string $c) => $c !== $organisateur));
                $p = (new Rencontre())->setCategorie($this->categories[$cat])->setDate($date)->setHeure('U9' === $cat ? '10h00' : '14h00')
                    ->setCompetition(Rencontre::PLATEAU)->setDomicile($organisateur)->setExterieur(self::NOM_EQUIPE)
                    ->setLieu($chezNous ? 'Stade des Coteaux' : 'Stade de ' . ucwords(mb_strtolower(explode(' ', $organisateur, 2)[1])))
                    ->setClubsPlateau($chezNous ? $invites : \array_slice($invites, 0, 2));
                if ($date < $aujourdhui) {
                    $adversaires = array_merge($chezNous ? [] : [$organisateur], $p->getClubsPlateau());
                    $p->setMatchsPlateau(array_map(fn (string $a) => ['adversaire' => $a, 'pour' => mt_rand(0, 4), 'contre' => mt_rand(0, 3)], $adversaires));
                    if (0 === $n % 2) {
                        $p->setRapport('Bon plateau : beaucoup d\'envie et de belles combinaisons. À travailler : les replacements après la perte du ballon.');
                    }
                }
                $this->em->persist($p);
                if ('U11' === $cat && !$convoque && $date > $aujourdhui) {
                    $convoque = true;
                    $convocation = (new Convocation($p))->setJoueurs(\array_slice($this->joueurs[$cat], 0, 10))->setRdvHeure('13h15')->setRdvLieu('Parking du stade des Coteaux')
                        ->setMessage('Prévoir la tenue, une gourde et le goûter.');
                    foreach ($convocation->getConvoques() as $k => $c) {
                        if ($k < 6) {
                            $c->repondre(Convoque::PRESENT, null, 'Parent');
                        } elseif (6 === $k) {
                            $c->repondre(Convoque::ABSENT, 'Malade', 'Parent');
                        }
                    }
                    $convocation->marquerEnvoyee();
                    $this->em->persist($convocation);
                }
            }
        }
    }

    /**
     * U19 : toute la saison au calendrier, pour montrer une saison entière enregistrée.
     * Championnat aller-retour contre 11 adversaires (22 journées, de septembre à avril, trêves de la Toussaint,
     * de Noël, de février et de Pâques) et 2 tours de coupe ; les matchs passés ont leur score et leur feuille de match.
     */
    private function saisonComplete(): void
    {
        $categorie = $this->categories['U19'];
        $effectif = $this->joueurs['U19'];
        $aujourdhui = new \DateTimeImmutable('today');
        // Premier samedi à partir du 5 septembre de l'année où commence la saison.
        $debut = new \DateTimeImmutable(substr($this->saison, 0, 4) . '-09-04');
        $debut = $debut->modify('next saturday');
        $adversaires = \array_slice(self::ADVERSAIRES, 1, 11);
        // Semaines après la 1re journée : aller (pas de match la 8e semaine, Toussaint), coupe, trêve de Noël, retour.
        $semainesAller = [0, 1, 2, 3, 4, 5, 6, 8, 9, 10, 11];
        $semainesRetour = [19, 20, 21, 22, 23, 24, 26, 27, 28, 29, 31];
        $matchs = [];
        foreach ($adversaires as $n => $adversaire) {
            $matchs[] = [$semainesAller[$n], $adversaire, 0 === $n % 2, 'Championnat'];
            $matchs[] = [$semainesRetour[$n], $adversaire, 1 === $n % 2, 'Championnat'];
        }
        // Le reste de la poule (12 équipes) : à la journée n, le club reçoit ou va chez le n-ième adversaire
        // et les 10 autres se rencontrent deux à deux (méthode du tourniquet : chaque paire une fois à l'aller,
        // une fois au retour, terrain inversé). Scores pour les journées passées : le classement se calcule seul.
        foreach ([$semainesAller, $semainesRetour] as $phase => $semaines) {
            foreach ($semaines as $n => $semaine) {
                $date = $debut->modify("+$semaine weeks");
                for ($k = 1; $k <= 5; ++$k) {
                    [$a, $b] = [$adversaires[($n + $k) % 11], $adversaires[($n - $k + 11) % 11]];
                    [$dom, $ext] = (0 === $k % 2) === (0 === $phase) ? [$a, $b] : [$b, $a];
                    $joue = $date < $aujourdhui;
                    $this->em->persist((new ResultatPoule($categorie, $this->saison, $date, $dom, $ext, $joue ? mt_rand(0, 4) : null, $joue ? mt_rand(0, 3) : null))
                        ->setJournee($n + 1 + 11 * $phase));
                }
            }
        }
        $matchs[] = [13, 'FC SAINT-LAURENT', false, 'Coupe Gambardella'];
        $matchs[] = [18, 'US VALLÉE VERTE', true, 'Coupe Gambardella'];
        // Analyse de l'adversaire : organisation observée chez chaque adversaire, vidéo des matchs joués à domicile.
        $organisations = ['4 3 3', '4 4 2', '4 2 3 1', '3 5 2', '4 1 4 1'];
        $prochain = null;
        usort($matchs, fn (array $a, array $b) => $a[0] <=> $b[0]);
        foreach ($matchs as [$semaine, $adversaire, $domicile, $competition]) {
            $date = $debut->modify("+$semaine weeks");
            $r = (new Rencontre())->setCategorie($categorie)->setDate($date)->setHeure('15h30')->setCompetition($competition)
                ->setDomicile($domicile ? self::NOM_EQUIPE : $adversaire)->setExterieur($domicile ? $adversaire : self::NOM_EQUIPE)
                ->setLieu($domicile ? 'Stade des Coteaux' : 'Stade de ' . ucwords(mb_strtolower(explode(' ', $adversaire, 2)[1])));
            if ($date < $aujourdhui) {
                [$pour, $contre] = [mt_rand(0, 4), mt_rand(0, 3)];
                $r->setButsDomicile($domicile ? $pour : $contre)->setButsExterieur($domicile ? $contre : $pour)
                    ->setButsDomicileMiTemps(intdiv($domicile ? $pour : $contre, 2))->setButsExterieurMiTemps(intdiv($domicile ? $contre : $pour, 2))
                    ->setTerrain(0 === $semaine % 3 ? 'Synthétique' : 'Herbe')->setMeteo(['Ensoleillé', 'Nuageux', 'Pluie'][$semaine % 3])->setSysteme('4 3 3')
                    ->setSystemeAdverse($organisations[crc32($adversaire) % \count($organisations)])
                    ->setLienVideo($domicile ? 'https://app.veo.co/' : null);
                $this->feuille($r, $effectif, $pour, $contre);
            } elseif (!$prochain && 'Championnat' === $competition) {
                $prochain = $adversaire;
                $this->tableauxTactiques($r);
            }
            $this->em->persist($r);
        }
        // Notes de l'éducateur et préparation du match retour, sur le prochain adversaire de championnat.
        if ($prochain) {
            $this->em->persist((new FicheAdversaire($categorie, $prochain))
                ->setPointsForts("Pressing haut dès la perte du ballon.\nAiliers très rapides, surtout côté droit.")
                ->setPointsFaibles("Espace dans le dos de la défense quand les latéraux montent.\nRelance difficile sous pression.")
                ->setARetenir('Forte intensité en début de match : rester compacts les 20 premières minutes.')
                ->setPreparation("4 2 3 1 : deux milieux devant la défense pour couper les ailiers.\nJouer vite dans leur dos dès la récupération.\nPresser leur gardien sur les relances courtes.\nÊtre vigilant sur les corners (marquage au premier poteau).")
                ->setSystemeHabituel('4 3 3')
                ->setTheme('pressing', 'Pressing haut et intense les 20 premières minutes.', 4)
                ->setTheme('relance', 'Relance courte, privilégie les côtés.', 3)
                ->setTheme('cpa', 'Dangereux sur corners (jeu au premier poteau).', 4)
                ->setJoueursDangereux([
                    ['numero' => 10, 'poste' => 'Meneur de jeu', 'note' => 'Créatif, bon tireur, passe décisive.'],
                    ['numero' => 7, 'poste' => 'Ailier droit', 'note' => 'Très rapide, provoque beaucoup.'],
                    ['numero' => 9, 'poste' => 'Attaquant', 'note' => 'Bon jeu de tête, puissant.'],
                ]));
        }
    }

    /**
     * Tableaux tactiques du match retour des U19 (causerie) : pressing sur la relance adverse, animé en deux étapes
     * et montré aux joueurs convoqués ; corners offensifs.
     */
    private function tableauxTactiques(Rencontre $r): void
    {
        $j = fn (float $x, float $y, string $n, string $c = 'rouge') => ['t' => 'joueur', 'x' => $x, 'y' => $y, 'c' => $c, 'n' => $n];
        $f = fn (float $x1, float $y1, float $x2, float $y2, string $s = 'course', string $c = 'blanc') => ['t' => 'fleche', 'x1' => $x1, 'y1' => $y1, 'x2' => $x2, 'y2' => $y2, 's' => $s, 'c' => $c];
        // Nous en 4 2 3 1 (attaque vers la droite), l'adversaire en 4 4 2 qui relance depuis son gardien.
        $objets = [
            $j(7, 37, '1'), $j(42, 62, '2'), $j(40, 45, '4'), $j(40, 29, '5'), $j(42, 12, '3'), $j(55, 44, '6'), $j(55, 30, '8'),
            $j(72, 62, '7'), $j(70, 37, '10'), $j(72, 12, '11'), $j(85, 37, '9'),
            $j(104, 37, '1', 'bleu'), $j(92, 14, '2', 'bleu'), $j(95, 30, '4', 'bleu'), $j(95, 44, '5', 'bleu'), $j(92, 60, '3', 'bleu'),
            $j(80, 12, '7', 'bleu'), $j(82, 30, '6', 'bleu'), $j(82, 44, '8', 'bleu'), $j(80, 62, '11', 'bleu'), $j(66, 30, '9', 'bleu'), $j(66, 44, '10', 'bleu'),
            ['t' => 'ballon', 'x' => 101.5, 'y' => 39],
            ['t' => 'zone', 'x' => 86, 'y' => 8, 'w' => 21, 'h' => 58, 'c' => 'rouge', 's' => 'pressing'],
            $f(86, 37, 99, 38.5), $f(73, 61, 89, 59), $f(73, 13, 89, 15), $f(71, 38, 90, 42),
            ['t' => 'texte', 'x' => 96, 'y' => 70, 'v' => 'Pressing !', 'c' => 'blanc'],
        ];
        $etape = fn (array $places) => array_map(fn ($i) => $places[$i] ?? null, array_keys($objets));
        $pressing = (new TableauTactique($r))->setOrdre(1)->setPartage(true)->setTitre('Pressing sur leur relance')
            ->setConsignes("Leur gardien relance court : on déclenche ensemble.\nLe 9 va sur le gardien en coupant le côté gauche.\nLe 10 ferme leur défenseur central, 7 et 11 sautent sur les latéraux.\nLes milieux 6 et 8 remontent : bloc compact, pas d'espace entre les lignes.")
            ->setSchema(['terrain' => 'entier', 'objets' => $objets, 'etapes' => [
                $etape([10 => [99, 38.5], 8 => [90, 42], 7 => [89, 59], 9 => [89, 15], 5 => [66, 44], 6 => [66, 30]]),
                $etape([22 => [93.5, 17], 9 => [91, 18], 6 => [80, 22]]),
            ]]);
        $corner = (new TableauTactique($r))->setOrdre(2)->setTitre('Corners offensifs')
            ->setConsignes("Tireur : 11, ballon rentrant au deuxième poteau.\n4 et 5 attaquent le premier poteau et le point de penalty, 9 le deuxième poteau.\n6 reste à l'entrée de la surface pour les seconds ballons.")
            ->setSchema(['terrain' => 'demi', 'objets' => [
                ['t' => 'ballon', 'x' => 4, 'y' => 4], $j(3, 6, '11'),
                $j(30, 22, '4'), $j(38, 20, '5'), $j(45, 22, '9'), $j(37, 30, '6'),
                $j(37, 4.5, '1', 'bleu'), $j(31, 8, '2', 'bleu'), $j(39, 10, '4', 'bleu'), $j(44, 9, '5', 'bleu'),
                $f(30, 22, 32, 8), $f(38, 20, 37.5, 13), $f(45, 22, 43, 8),
                ['t' => 'fleche', 'x1' => 5, 'y1' => 4, 'x2' => 42, 'y2' => 9, 's' => 'passe', 'c' => 'jaune', 'p' => [[24, 1.5]]],
            ]]);
        $this->em->persist($pressing);
        $this->em->persist($corner);
    }

    /**
     * Numéros de maillot des convoqués d'un match à venir (saisie en direct) : 1 à 11, puis des numéros hors série.
     *
     * @param list<Joueur> $convoques
     */
    private function numeroter(Rencontre $r, array $convoques): void
    {
        $numeros = [1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 14, 17, 23, 21, 27];
        foreach ($convoques as $i => $j) {
            $r->setNumeroDirect($j->getId(), $numeros[$i] ?? $i + 1);
        }
    }

    /** @param list<Joueur> $effectif */
    private function feuille(Rencontre $r, array $effectif, int $pour, int $contre): void
    {
        // Chaque joueur joue à son poste (celui de sa fiche, voir joueurs()) : le 1er de l'effectif au but, etc.
        // Les 11 titulaires (11 joueurs consécutifs de l'effectif) couvrent ainsi les 11 postes une fois chacun.
        $postes = ['Gardien', 'Défenseur central droit', 'Défenseur central gauche', 'Latéral droit', 'Latéral gauche', 'Milieu défensif axe',
            'Milieu relayeur gauche', 'Milieu offensif droit', 'Milieu offensif gauche', 'Attaquant', 'Attaquant'];
        $decalage = mt_rand(0, 3);
        $retenus = \array_slice(array_merge(\array_slice($effectif, $decalage), \array_slice($effectif, 0, $decalage)), 0, 14);
        $presents = [];
        foreach ($retenus as $n => $j) {
            $poste = $n < 13 ? $postes[array_search($j, $effectif, true) % \count($postes)] : 'Non utilisé';
            $p = (new Participation())->setJoueur($j)->setNumero($n + 1)->setPoste($poste)
                ->setTempsJeu($n < 11 ? (8 === $n ? 70 : 90) : (11 === $n ? 20 : (12 === $n ? 10 : null)))->setNote($n < 13 ? round(mt_rand(45, 80) / 10, 1) : null);
            if (0 === mt_rand(0, 9) && $n < 11) {
                $p->setCarton('JJ')->setMinuteCarton((string) mt_rand(20, 85));
            }
            $r->addParticipation($p);
            if ($n < 13) {
                $presents[] = $j;
            }
        }
        $buteurs = \array_slice($presents, 6, 7);
        for ($b = 0; $b < $pour; ++$b) {
            $buteur = $buteurs[mt_rand(0, \count($buteurs) - 1)];
            $passeur = $buteurs[mt_rand(0, \count($buteurs) - 1)];
            $r->addBut((new But())->setMinute(mt_rand(3, 88))->setButeur($buteur)->setPasseur($passeur !== $buteur ? $passeur : null)
                ->setGeste(['Pied droit', 'Pied gauche', 'Tête'][mt_rand(0, 2)])->setPhase(['AP', 'AR', 'CPA'][mt_rand(0, 2)]));
        }
        for ($b = 0; $b < $contre; ++$b) {
            $r->addBut((new But())->setMinute(mt_rand(3, 88))->setAdverse(true));
        }
    }

    private function entrainements(): void
    {
        foreach (['U9' => [[3, '14h00', '15h15']], 'U11' => [[3, '15h30', '17h00']], 'U13' => [[2, '18h00', '19h30'], [4, '18h00', '19h30']],
            'U15' => [[1, '18h30', '20h00'], [3, '18h30', '20h00']], 'U17' => [[2, '19h00', '20h30'], [4, '19h00', '20h30']],
            'SENIORS' => [[3, '20h00', '21h30'], [5, '20h00', '21h30']]] as $cat => $creneaux) {
            foreach ($creneaux as [$jour, $debut, $fin]) {
                $this->em->persist((new CreneauEntrainement())->setCategorie($this->categories[$cat])->setJour($jour)->setHeureDebut($debut)->setHeureFin($fin)
                    ->setLieu(\in_array($cat, ['U9', 'U11'], true) ? 'Terrain annexe' : 'Synthétique des Coteaux'));
            }
        }
    }

    // ———————————————————————— Vie du club ————————————————————————

    private function vieDuClub(): void
    {
        $aujourdhui = new \DateTimeImmutable('today');
        foreach ([['Loto du club', 'Loto / tombola', '+12 days 20:00', 'Salle des fêtes des Coteaux', true], ['Assemblée générale', 'Assemblée générale', '+40 days 18:30', 'Club-house', true],
            ['Réunion des éducateurs', 'Réunion', '+5 days 19:00', 'Club-house', false], ['Repas de fin d\'année', 'Repas / soirée', '+30 days 19:30', 'Club-house', true]] as [$titre, $type, $quand, $lieu, $familles]) {
            $this->em->persist((new Evenement())->setTitre($titre)->setType($type)->setDebut($aujourdhui->modify($quand))->setLieu($lieu)->setVisibleFamilles($familles)
                ->setDescription('Organisé par le bureau du club.'));
        }
        $stage = (new Stage())->setTitre('Stage de Noël')->setRaison('Perfectionnement technique')->setDebut($aujourdhui->modify('+35 days'))->setFin($aujourdhui->modify('+37 days'))
            ->setHoraires('9 h – 16 h')->setLieu('Stade des Coteaux')->setTarif('60')->setDetailTarif('Repas du midi compris')->setPlaces(24)
            ->setInscriptionsFamilles(true)->setDateLimite($aujourdhui->modify('+25 days'))->setPrevenuLe(new \DateTimeImmutable('-3 days'))
            ->setDescription('Trois jours de technique, jeux et tournoi le dernier après-midi.');
        foreach (['U11', 'U13', 'U15'] as $cat) {
            $stage->addCategory($this->categories[$cat]);
        }
        $this->em->persist($stage);
        foreach ([$this->joueurs['U13'][0], $this->joueurs['U13'][4], $this->joueurs['U15'][1], $this->joueurs['U11'][5], $this->joueurs['U15'][7]] as $n => $j) {
            $this->em->persist($stage->inscrire($j, true)->setPaye($n < 2));
        }
        $this->em->persist((new Tournoi())->setNom('Tournoi de la Vallée')->setCategorie($this->categories['U13'])->setDebut($aujourdhui->modify('+19 days'))
            ->setLieu('Montvert')->setOrganisateur('AS Montvert')->setFormat('Poules puis phases finales, matchs de 15 min')->setTransport('Voitures des parents')
            ->setCoutInscription(40)->setDescription('12 équipes engagées.'));
    }

    private function finances(): void
    {
        $tarifs = ['U7' => 110, 'U9' => 120, 'U11' => 130, 'U13' => 150, 'U15' => 150, 'U17' => 170, 'U19' => 170, 'SENIORS' => 190, 'VÉTÉRANS' => 100];
        foreach ($tarifs as $cat => $montant) {
            if (isset($this->categories[$cat])) {
                $this->em->persist((new TarifCotisation($this->categories[$cat], $this->saison))->setMontant($montant));
            }
        }
        $modes = ['Chèque', 'Virement', 'Espèces', 'Carte bancaire', 'Pass\'Sport'];
        $n = 0;
        foreach ($this->joueurs as $cat => $liste) {
            foreach ($liste as $j) {
                ++$n;
                if (0 === $n % 5) {
                    continue; // pas encore réglé
                }
                $cotisation = new Cotisation($j, $this->saison);
                $tarif = $tarifs[$cat];
                $paye = 0 === $n % 3 ? $tarif / 2 : $tarif;
                $cotisation->ajouterPaiement(new Paiement($cotisation, new \DateTimeImmutable('-' . (20 + $n % 60) . ' days'), $paye, $modes[$n % \count($modes)]));
                $this->em->persist($cotisation);
            }
        }
        foreach ([['recette', 'Entrées des matchs', 'Match Seniors', -25, 180], ['recette', 'Buvette', 'Buvette du week-end', -18, 245], ['recette', 'Subventions', 'Subvention municipale', -40, 1500],
            ['recette', 'Sponsoring', 'Garage des Coteaux', -50, 800], ['depense', 'Matériel', 'Ballons et chasubles', -45, 420], ['depense', 'Arbitrage', 'Frais d\'arbitrage', -12, 180],
            ['depense', 'Assurance', 'Assurance du club', -60, 950], ['depense', 'Licences / engagements', 'Engagements des équipes', -70, 1240]] as [$sens, $rubrique, $libelle, $jours, $montant]) {
            $this->em->persist((new Operation())->setSens($sens)->setRubrique($rubrique)->setLibelle($libelle)->setDate(new \DateTimeImmutable("$jours days"))->setMontant($montant));
        }
        $this->em->persist((new Contrat())->setObjet('Assurance responsabilité civile du club')->setType('Assurance')->setPartie('Mutuelle des Sports')
            ->setDebut(new \DateTimeImmutable('2026-09-01'))->setFin(new \DateTimeImmutable('+45 days'))->setMontant(950)->setPeriodicite('Par an'));
        foreach ([['Garage des Coteaux', 'Entreprise', 'M. Bonnet', 'Argent', '800'], ['Boulangerie du Centre', 'Entreprise', 'Mme Leroy', 'Lots / dons', '150'],
            ['Commune des Coteaux', 'Collectivité', 'Service des sports', 'Argent', '1500']] as [$nom, $type, $contact, $nature, $montant]) {
            $s = (new Sponsor())->setNom($nom)->setType($type)->setContact($contact)->setVille('Les Coteaux')->setTelephone('02 41 11 22 33');
            $this->em->persist($s);
            $this->em->persist((new PartenariatSponsor())->setSponsor($s)->setSaison($this->saison)->setNature($nature)->setMontant($montant));
        }
        // Transport : minibus du club et conducteur au permis contrôlé.
        $this->em->persist((new Vehicule())->setNom('Minibus du club')->setImmatriculation('AB-123-CD')->setPlaces(9)->setKilometrage(84500)
            ->setAssurance(new \DateTimeImmutable('+5 months'))->setControleTechnique(new \DateTimeImmutable('+20 days'))->setRevision(new \DateTimeImmutable('+3 months')));
        $this->em->persist((new Conducteur())->setNom('Karim Benali')->setTelephone('06 20 30 43 50')->setCategoriesPermis('B')->setControleLe(new \DateTimeImmutable('-2 months'))
            ->setControlePar('Jean Morel')->setMinibus(true));
    }

    private function boutique(Utilisateur $parent): void
    {
        $articles = [];
        foreach ([['Survêtement du club', 45, '8 ans, 10 ans, 12 ans, 14 ans, S, M, L, XL', ['8 ans' => 4, '10 ans' => 0, '12 ans' => 3, '14 ans' => 5, 'S' => 6, 'M' => 4, 'L' => 2, 'XL' => 1]],
            ['Maillot domicile', 30, '8 ans, 10 ans, 12 ans, 14 ans, S, M, L, XL', null], ['Chaussettes', 8, '', null], ['Sac de sport', 25, '', ['Taille unique' => 7]]] as $i => [$nom, $prix, $tailles, $stock]) {
            $a = (new ArticleBoutique())->setNom($nom)->setPrix($prix)->setTaillesTexte($tailles)->setOrdre($i)->setDescription('Aux couleurs du club, logo brodé.');
            $a->setStock($stock);
            $this->em->persist($a);
            $articles[] = $a;
        }
        $enfant = $parent->getJoueurs()->first();
        $commande = (new CommandeBoutique($parent, 'Sophie ' . $parent->getNom(), $enfant))->setNote('Flocage du prénom au dos');
        $commande->ajouterLigne($articles[0], '14 ans', 1)->ajouterLigne($articles[2], 'Taille unique', 2)->marquerPayee('Chèque');
        $this->em->persist($commande);
        $autre = $this->joueurs['U13'][2];
        $deuxieme = (new CommandeBoutique($parent, 'Famille ' . $autre->getNom(), $autre));
        $deuxieme->ajouterLigne($articles[1], '12 ans', 1);
        $this->em->persist($deuxieme);
    }

    // ———————————————————————— Suivi sportif et organisation ————————————————————————

    /**
     * Dernier match joué des U15 noté en direct : passes, duels, contrôles, frappes, centres, corners répartis sur le terrain
     * (plus de frappes et de centres près du but adverse), et les buts de la feuille à leur minute.
     *
     * @param array<string, list<Rencontre>> $matchs
     */
    private function actionsEnDirect(array $matchs): void
    {
        $r = end($matchs['U15']);
        if (!$r) {
            return;
        }
        $duree = $r->getCategorie()?->getDureeMatch() ?: 80;
        $joueurs = [];
        foreach ($r->getParticipations() as $p) {
            if ('Non utilisé' !== $p->getPoste()) {
                $joueurs[] = $p->getJoueur();
            }
        }
        $types = ['BP' => 30, 'MP' => 10, 'DG' => 12, 'DP' => 9, 'BC' => 10, 'MC' => 5, 'FC' => 3, 'FH' => 3, 'TC' => 2, 'TH' => 2, 'C' => 5, 'CO' => 2, 'CF' => 1, 'AC' => 2, 'AH' => 2, 'ATC' => 1, 'ATH' => 1, 'CA' => 2, 'CFA' => 1];        $tirage = [];
        foreach ($types as $type => $poids) {
            array_push($tirage, ...array_fill(0, $poids, $type));
        }
        $n = 0;
        $ajouter = function (string $type, ?Joueur $joueur, int $minute, int $zx, int $zy, ?array $details = null) use ($r, $duree, &$n): void {
            $this->em->persist((new ActionMatch($r, \sprintf('d0000000-0000-4000-8000-%012d', ++$n + 1000 * (int) $r->getId())))
                ->setType($type)->setJoueur($joueur)->placer($minute > intdiv($duree, 2) ? 2 : 1, $minute, $zx, $zy)->setDetails($details));
        };
        for ($i = 0; $i < 170; ++$i) {
            $type = $tirage[mt_rand(0, \count($tirage) - 1)];
            [$zx, $zy] = match ($type) {
                'FC', 'FH', 'TC', 'TH' => [mt_rand(4, 5), mt_rand(0, 2)],
                'C', 'CO' => [5, [0, 2][mt_rand(0, 1)]],
                'AC', 'AH', 'ATC', 'ATH' => [mt_rand(0, 1), mt_rand(0, 2)],
                'CA' => [0, [0, 2][mt_rand(0, 1)]],
                'CF' => [mt_rand(3, 4), mt_rand(0, 2)],
                'CFA' => [mt_rand(1, 2), mt_rand(0, 2)],
                default => [mt_rand(0, 5), mt_rand(0, 2)],
            };
            $joueur = isset(ActionMatch::ADVERSES[$type]) || 'CF' === $type ? null : $joueurs[mt_rand(0, \count($joueurs) - 1)];
            $ajouter($type, $joueur, mt_rand(1, $duree), $zx, $zy);
        }
        $premier = ['B' => true, 'BA' => true];
        foreach ($r->getButs() as $but) {
            // Tracé du but : tir depuis la surface, entrée entre les poteaux (30,34 à 37,66 m).
            $adverse = $but->isAdverse();
            $x = $adverse ? mt_rand(40, 160) / 10 : 105 - mt_rand(40, 160) / 10;
            $y = mt_rand(220, 460) / 10;
            $arrivee = [$adverse ? 0 : 105, mt_rand(310, 370) / 10];
            // Coups de pied arrêtés : notre 1er but de la tête sur corner (tiré par le passeur), le 1er but adverse sur penalty.
            $situation = null;
            if ($premier[$adverse ? 'BA' : 'B']) {
                $premier[$adverse ? 'BA' : 'B'] = false;
                $situation = $adverse ? 'penalty' : ($but->getPasseur() ? 'corner' : null);
                if ('penalty' === $situation) {
                    [$x, $y] = [11.0, 34.0];
                } elseif ('corner' === $situation) {
                    [$x, $y] = [105 - mt_rand(40, 70) / 10, mt_rand(270, 330) / 10];
                    $but->setGeste('Tête');
                }
                if ($situation) {
                    $but->setConditions(ActionMatch::conditionsDeSituation($situation, [$x, $y], $adverse))->setPhase('CPA');
                }
            }
            $ajouter($adverse ? 'BA' : 'B', $adverse ? null : $but->getButeur(), min($duree, (int) $but->getMinute()), $adverse ? 0 : 5, (int) min(2, floor($y / (68 / 3))), array_filter([
                'depart' => [$x, $y], 'arrivee' => $arrivee, 'geste' => 'Tête' === $but->getGeste() ? 'tete' : 'tir',
                'passeur' => $adverse ? null : $but->getPasseur()?->getId(),
                'situation' => $situation,
            ], fn ($v) => null !== $v));
            // Même dessin sur la feuille de match (direct et feuille se tiennent à jour l'un l'autre).
            $but->setTrace(['depart' => [$x, $y], 'arrivee' => $arrivee]);
        }
        // Un carton jaune de chaque côté (le nôtre aussi sur la feuille de match).
        $ajouter('CJ', $joueurs[3] ?? null, 34, 2, 1);
        foreach ($r->getParticipations() as $p) {
            if (isset($joueurs[3]) && $p->getJoueur() === $joueurs[3]) {
                $p->setCarton('JJ')->setMinuteCarton('34');
            }
        }
        $ajouter('JA', null, 58, 3, 2);
    }

    /** @param array<string, list<Rencontre>> $matchs */
    /**
     * Bibliothèque de coups de pied arrêtés : un corner offensif animé (écran, appels, finition), un corner défensif,
     * un coup franc et une touche, avec les rôles de chaque joueur.
     */
    private function bibliothequeCpa(): void
    {
        $j = fn (float $x, float $y, string $n, string $c = 'rouge') => ['t' => 'joueur', 'x' => $x, 'y' => $y, 'c' => $c, 'n' => $n];
        $f = fn (float $x1, float $y1, float $x2, float $y2, string $s = 'course', string $c = 'blanc', array $p = []) => ['t' => 'fleche', 'x1' => $x1, 'y1' => $y1, 'x2' => $x2, 'y2' => $y2, 's' => $s, 'c' => $c] + ($p ? ['p' => $p] : []);
        // Corner offensif : tireur 10, écran du 5 sur le défenseur, 9 au 1er poteau, 7 en profondeur au 2e poteau, 8 en soutien.
        $objets = [
            ['t' => 'ballon', 'x' => 70, 'y' => 4], $j(71.5, 6, '10'), $j(37, 5, '1', 'bleu'),
            $j(30, 22, '9'), $j(40, 19, '5'), $j(46, 24, '4'), $j(24, 26, '7'), $j(36, 31, '8'), $j(50, 30, '6'),
            $j(33, 10, '', 'bleu'), $j(40, 12, '', 'bleu'), $j(44, 9, '', 'bleu'), $j(30, 16, '', 'bleu'),
            $f(68, 5, 33, 9, 'passe', 'jaune', [[52, 0.5]]), $f(30, 21, 33, 10), $f(24, 25, 26, 11), $f(50, 29, 43, 24),
        ];
        $etape1 = array_fill(0, \count($objets), null);
        $etape1[3] = [33, 11];
        $etape1[4] = [38, 13];
        $etape1[6] = [27, 12];
        $etape1[8] = [36, 24];
        $etape1[5] = [44, 17];
        $etape2 = array_fill(0, \count($objets), null);
        $etape2[0] = [33, 10, [[52, 1]]];
        $corner = (new CombinaisonCpa())->setTitre('Corner rentrant, écran au premier poteau')->setType('corner')->setCote('offensif')
            ->setConsignes("Signal du tireur : bras levé. Ballon rentrant tendu au 1er poteau.\nLe 5 fait écran sur le défenseur du 9 ; le 7 arrive lancé au 2e poteau.")
            ->setSchema(['terrain' => 'demi', 'objets' => $objets, 'etapes' => [$etape1, $etape2]])
            ->setRoles([
                ['n' => '10', 'role' => 'Tireur', 'detail' => 'corner rentrant'], ['n' => '9', 'role' => 'Finition 1er poteau', 'detail' => 'attaque le 1er poteau'],
                ['n' => '5', 'role' => 'Écran', 'detail' => 'gêne le défenseur'], ['n' => '7', 'role' => 'Appel 2e poteau', 'detail' => 'arrivée en profondeur'],
                ['n' => '8', 'role' => 'Soutien (zone de reprise)', 'detail' => 'entrée de surface'], ['n' => '4', 'role' => 'Finition au point de penalty'],
                ['n' => '6', 'role' => 'Repli', 'detail' => 'couvre la contre-attaque'],
            ]);
        $defensif = (new CombinaisonCpa())->setTitre('Défense de zone sur corner')->setType('corner')->setCote('defensif')
            ->setConsignes("Trois joueurs en ligne aux 6 mètres, deux au point de penalty, un au 1er poteau.\nOn attaque le ballon, on ne recule pas.")
            ->setSchema(['terrain' => 'demi', 'objets' => [
                ['t' => 'ballon', 'x' => 70, 'y' => 4], $j(71.5, 6, '', 'bleu'), $j(37, 5, '1'),
                $j(31, 9, '2'), $j(37, 9.5, '5'), $j(43, 9, '4'), $j(34, 15, '6'), $j(40, 15, '8'), $j(33.5, 5, '3'), $j(37, 30, '9'),
                ['t' => 'zone', 'x' => 28, 'y' => 6.5, 'w' => 18, 'h' => 11, 'c' => 'jaune'],
            ]])
            ->setRoles([['n' => '1', 'role' => 'Gardien', 'detail' => 'sort sur les ballons dans les 6 m'], ['n' => '3', 'role' => 'Marquage de zone', 'detail' => '1er poteau'],
                ['n' => '2', 'role' => 'Marquage de zone'], ['n' => '5', 'role' => 'Marquage de zone'], ['n' => '4', 'role' => 'Marquage de zone'],
                ['n' => '6', 'role' => 'Marquage de zone', 'detail' => 'point de penalty'], ['n' => '8', 'role' => 'Marquage de zone', 'detail' => 'point de penalty'],
                ['n' => '9', 'role' => 'Appel', 'detail' => 'reste haut pour la contre-attaque']]);
        $coupFranc = (new CombinaisonCpa())->setTitre('Coup franc à 22 m : feinte et décalage')->setType('coup_franc')->setCote('offensif')
            ->setConsignes('Le 8 passe au-dessus du ballon (feinte), le 10 décale pour la frappe du 6 à l\'entrée de la surface.')
            ->setSchema(['terrain' => 'demi', 'objets' => [
                $j(37, 5, '1', 'bleu'), ['t' => 'ballon', 'x' => 30, 'y' => 26], $j(29, 28, '10'), $j(32, 30, '8'), $j(38, 30, '6'),
                $j(31, 19, '', 'bleu'), $j(32.6, 19, '', 'bleu'), $j(34.2, 19, '', 'bleu'), $j(35.8, 19, '', 'bleu'),
                $j(40, 14, '9'), $j(45, 15, '5'), $f(32, 29, 29, 25), $f(30, 26, 37, 27, 'passe', 'jaune'),
            ]])
            ->setRoles([['n' => '8', 'role' => 'Leurre', 'detail' => 'passe au-dessus du ballon'], ['n' => '10', 'role' => 'Tireur', 'detail' => 'décalage court'],
                ['n' => '6', 'role' => 'Tireur', 'detail' => 'frappe en une touche'], ['n' => '9', 'role' => 'Finition au point de penalty', 'detail' => 'suit la frappe'], ['n' => '5', 'role' => 'Finition 2e poteau']]);
        $touche = (new CombinaisonCpa())->setTitre('Touche longue dans la surface')->setType('touche')->setCote('offensif')
            ->setConsignes('Le 2 lance long vers le 1er poteau ; le 9 dévie, le 7 et le 5 attaquent le 2e poteau.')
            ->setSchema(['terrain' => 'demi', 'objets' => [
                $j(37, 5, '1', 'bleu'), ['t' => 'ballon', 'x' => 2, 'y' => 16], $j(3, 16, '2'), $j(28, 12, '9'), $j(36, 18, '7'), $j(42, 16, '5'),
                $j(30, 13, '', 'bleu'), $j(38, 14, '', 'bleu'), $f(3, 15, 28, 11, 'passe', 'jaune', [[15, 7]]), $f(36, 17, 40, 8), $f(42, 15, 44, 9),
            ]])
            ->setRoles([['n' => '2', 'role' => 'Tireur', 'detail' => 'touche longue'], ['n' => '9', 'role' => 'Finition 1er poteau', 'detail' => 'dévie de la tête'],
                ['n' => '7', 'role' => 'Finition 2e poteau'], ['n' => '5', 'role' => 'Finition 2e poteau', 'detail' => 'arrivée lancée']]);
        foreach ([$corner, $defensif, $coupFranc, $touche] as $c) {
            $this->em->persist($c->setSchema($c->getSchema()));
        }
    }

    /**
     * État de forme des 7 derniers jours (U15 et U19), un jour sur deux environ, et aujourd'hui pour presque tous :
     * en forme, à surveiller, fatigués. L'enfant U15 du compte parent n'a pas encore répondu aujourd'hui.
     */
    private function etatsDeForme(): void
    {
        $aujourdhui = new \DateTimeImmutable('today');
        // fatigue, sommeil, douleurs, motivation
        $profils = [[2, 8, 1, 9], [3, 7, 0, 8], [5, 6, 2, 7], [3, 8, 1, 6], [7, 4, 3, 6], [2, 9, 0, 9], [4, 7, 6, 8], [5, 5, 1, 5], [1, 8, 0, 10], [6, 6, 4, 7]];
        foreach (['U15', 'U19'] as $cat) {
            foreach ($this->joueurs[$cat] as $i => $j) {
                for ($k = 6; $k >= 0; --$k) {
                    $parent = 'U15' === $cat && 2 === $i;
                    if ((0 === $k && ($parent || 0 === $i % 6)) || (0 !== $k && 0 !== ($i + $k) % 2)) {
                        continue;
                    }
                    $p = $profils[($i * 3 + $k) % \count($profils)];
                    $this->em->persist((new EtatForme($j, $aujourdhui->modify("-$k days")))->renseigner(
                        ['fatigue' => $p[0], 'sommeil' => $p[1], 'douleurs' => $p[2], 'motivation' => $p[3]],
                        6 === $p[2] ? 'Douleur à la cheville après le match' : null,
                        'Joueur',
                    ));
                }
            }
        }
    }

    /**
     * Présences de la semaine d'entraînement en cours (U15 et U19), avec la note et la forme de chaque joueur :
     * visibles dans la convocation et dans « Composer le onze » des matchs du week-end.
     */
    private function semainesEntrainement(): void
    {
        $lundi = new \DateTimeImmutable('monday this week');
        $formes = ['top', 'bien', 'bien', 'moyen', 'bien', 'top', 'faible', 'bien', 'moyen', 'bien', 'top', 'bien', 'moyen', 'bien', 'bien', 'faible'];
        foreach (['U15' => ['mar_a', 'jeu_a'], 'U19' => ['mar_a', 'mer_a', 'ven_a']] as $cat => $creneaux) {
            $semaine = (new SemainePresence())->setLundi($lundi)->setCategorie($this->categories[$cat]);
            foreach ($this->joueurs[$cat] as $i => $j) {
                // Un joueur sur sept absent une séance (blessé ou malade) ; les autres présents à toutes.
                $presences = array_fill_keys($creneaux, $semaine->getEquipeAffichee());
                if (0 === $i % 7) {
                    $presences[$creneaux[0]] = 'Malade';
                }
                $semaine->addPresence((new Presence())->setJoueur($j)->setCreneaux($presences)
                    ->setForme($formes[$i % \count($formes)])->setNoteSemaine([7.5, 6.5, 7, 5.5, 6, 8, 4.5, 6.5, 5.5, 7, 8, 6, 5, 6.5, 7, 4][$i % 16]));
            }
            $this->em->persist($semaine);
        }
    }

    /**
     * Objectifs individuels de progression, mis à jour chaque mois depuis la rentrée, et observations de l'entraîneur :
     * pour l'enfant U15 du compte parent (visible dans l'espace famille) et deux joueurs des U19.
     */
    private function objectifsIndividuels(): void
    {
        $aujourdhui = new \DateTimeImmutable('today');
        $debut = new \DateTimeImmutable(substr($this->saison, 0, 4) . '-08-25');
        $suivis = [
            [$this->joueurs['U15'][2], 'Karim Benali', [['Améliorer ma finition', 'finition', 75, 'Frapper du bon pied, viser les petits filets ; 20 frappes après chaque entraînement.'],
                ['Gagner en explosivité', 'physique', 60, 'Démarrages sur 10 m, travail des appuis.'], ['Renforcer mon jeu défensif', 'defense', 80, 'Repli immédiat à la perte du ballon.']],
                ['Bonne progression sur la finition. Continue dans cette dynamique et sois plus exigeant dans le dernier geste.', 'Très bon repli défensif samedi, c\'est exactement ce qu\'on attend.']],
            [$this->joueurs['U19'][9], 'Antoine Faure', [['Marquer 10 buts dans la saison', 'finition', 50, null], ['Jouer dos au but et remiser', 'technique', 65, 'Protéger le ballon, remiser en une touche.']],
                ['Bon travail dos au but, mais trop de touches de balle avant la remise.']],
            [$this->joueurs['U19'][0], 'Antoine Faure', [['Relances au pied plus précises', 'technique', 55, null], ['Diriger ma défense', 'mental', 70, 'Parler, placer la ligne sur les coups de pied arrêtés.']],
                ['Tu prends la parole de plus en plus : continue, ta défense est plus sereine.']],
        ];
        foreach ($suivis as [$joueur, $coach, $objectifs, $observations]) {
            $premier = null;
            foreach ($objectifs as $k => [$titre, $theme, $final, $description]) {
                $o = (new ObjectifJoueur($joueur, $this->saison))->setTitre($titre)->setTheme($theme)->setDescription($description)->setCreeParNom($coach);
                // Une mise à jour par mois, de la rentrée à aujourd'hui, jusqu'au pourcentage atteint.
                $etapes = [];
                for ($m = $debut; $m <= $aujourdhui; $m = $m->modify('+1 month')) {
                    $etapes[] = $m;
                }
                if (!$etapes || end($etapes)->format('Y-m') !== $aujourdhui->format('Y-m')) {
                    $etapes[] = $aujourdhui;
                }
                foreach ($etapes as $i => $date) {
                    $o->setProgression((int) (round(($final * ($i + 1) / \count($etapes)) / 5) * 5) ?: 5, $date);
                }
                $this->em->persist($o);
                $premier ??= $o;
            }
            foreach ($observations as $i => $texte) {
                $observation = (new ObservationJoueur($joueur))->setTexte($texte)->setAuteurNom($coach)->setObjectif(0 === $i ? $premier : null)
                    ->setDate($aujourdhui->modify('-' . (3 + 12 * $i) . ' days'));
                $observation->setLienVideo(0 === $i ? 'https://www.youtube.com/' : null);
                $this->em->persist($observation);
            }
        }
    }

    private function suiviSportif(array $matchs): void
    {
        $aujourdhui = new \DateTimeImmutable('today');
        foreach ([[$this->joueurs['SENIORS'][4], 'Entorse', 'Membre inférieur', 'Match', '-10 days', null, 3, 'semaines'],
            [$this->joueurs['U17'][6], 'Contracture', 'Membre inférieur', 'Entrainement', '-30 days', '-12 days', 2, 'semaines'],
            [$this->joueurs['U15'][9], 'Contusion', 'Membre supérieur', 'Hors foot', '-5 days', '+4 days', 10, 'jours']] as [$j, $type, $membre, $lieu, $date, $reprise, $arret, $unite]) {
            $this->em->persist((new Blessure())->setJoueur($j)->setType($type)->setMembre($membre)->setLieu($lieu)->setDate($aujourdhui->modify($date))
                ->setDateReprise($reprise ? $aujourdhui->modify($reprise) : null)->setDateRepriseMatch($reprise ? $aujourdhui->modify($reprise)->modify('+7 days') : null)
                ->setAvecArret(true)->setArretPrevu($arret)->setUniteArret($unite)->setCommentaire('Soins au club, suivi par le kiné.'));
        }
        // Tests physiques (catalogue du club) : VAMEVAL et sprint 20 m des U15, en septembre puis en novembre.
        $types = [];
        foreach ($this->catalogue->pour($this->club) as $t) {
            $types[$t->getCode()] = $t;
        }
        foreach ($this->joueurs['U15'] as $i => $j) {
            if (isset($types['vameval'])) {
                $this->em->persist(new ResultatTest($j, $types['vameval'], new \DateTimeImmutable('2026-09-20'), 11.5 + ($i % 5) * 0.5));
                $this->em->persist(new ResultatTest($j, $types['vameval'], $aujourdhui->modify('-6 days'), 12.0 + ($i % 6) * 0.5));
            }
            if (isset($types['sprint20'])) {
                $this->em->persist(new ResultatTest($j, $types['sprint20'], new \DateTimeImmutable('2026-09-20'), 3.55 - ($i % 4) * 0.05));
                $this->em->persist(new ResultatTest($j, $types['sprint20'], $aujourdhui->modify('-6 days'), 3.50 - ($i % 5) * 0.05));
            }
        }
        // Effort ressenti après le dernier match des U15.
        $dernier = end($matchs['U15']);
        foreach (\array_slice($this->joueurs['U15'], 0, 10) as $i => $j) {
            $this->em->persist(new Ressenti($j, $dernier, 5 + $i % 4, 80, 6 === $i ? 'Petite douleur à la cuisse' : null));
        }
        // Discipline.
        $this->em->persist((new Sanction())->setJoueur($this->joueurs['U17'][3])->setDate($aujourdhui->modify('-8 days'))->setMotif('Retards répétés à l\'entraînement')
            ->setLieu('Entraînement')->setSanctionSportive('Remplaçant au prochain match')->setGravite(1)->setAmende(10));
        $this->em->persist((new Sanction())->setJoueur($this->joueurs['SENIORS'][8])->setDate($aujourdhui->modify('-15 days'))->setMotif('Carton rouge pour contestation')
            ->setLieu('Match')->setSanctionSportive('2 matchs de suspension')->setGravite(2)->setAmende(20)->setAmendePayeeLe($aujourdhui->modify('-10 days')));
        $this->em->persist((new Sanction())->setJoueur($this->joueurs['SENIORS'][8])->setDate($aujourdhui->modify('-3 days'))->setMotif('Arrivé en retard au rendez-vous du match')
            ->setLieu('Match')->setGravite(1)->setAmende(5));
    }

    /** @param array<string, Utilisateur> $comptes */
    private function organisation(array $comptes): void
    {
        $aujourdhui = new \DateTimeImmutable('today');
        foreach ([['Commander les ballons de la saison', $comptes['president'], null, '+7 days', true], ['Préparer les licences des nouveaux U13', $comptes['educateur'], $this->categories['U13'], '+14 days', false],
            ['Réserver le car pour le tournoi de la Vallée', $comptes['educateur'], $this->categories['U13'], '+10 days', false]] as [$objet, $responsable, $categorie, $echeance, $urgente]) {
            $this->em->persist((new Tache())->setObjet($objet)->setResponsable($responsable)->setCategorie($categorie)->setEcheance($aujourdhui->modify($echeance))
                ->setUrgente($urgente)->setStatut('a_faire')->setCreePar($comptes['president']));
        }
        // Message de l'éducateur aux familles des U15 (aucun e-mail n'est envoyé ici).
        $message = (new Message())->setAuteur($comptes['educateur'])->setAuteurNom('Karim Benali')->setSujet('Entraînement de mercredi')
            ->setCorps("Bonjour à tous,\n\nL'entraînement de mercredi aura lieu sur le terrain synthétique.\n\nMerci, Karim")
            ->setCible('categorie')->setCibleLibelle('Catégorie U15')->setCategorie($this->categories['U15']);
        foreach ($this->joueurs['U15'] as $j) {
            $message->ajouterDestinataire($j, $comptes['parent']->getJoueurs()->contains($j) ? $comptes['parent'] : null);
        }
        $this->em->persist($message);
    }

    // ———————————————————————— Outils ————————————————————————

    /**
     * Supprime le club de démonstration et toutes ses données : chaque table qui a une colonne club_id est vidée
     * de ses lignes, puis les lignes des tables de liaison devenues orphelines (lignes de commande, équipes…).
     * Seul ce club est concerné : rien n'est touché dans les autres.
     */
    private function supprimer(Club $club): void
    {
        $db = $this->em->getConnection();
        $base = $db->fetchOne('SELECT DATABASE()');
        $avecClub = $db->fetchFirstColumn("SELECT TABLE_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND COLUMN_NAME = 'club_id'", [$base]);
        $liens = $db->fetchAllAssociative('SELECT TABLE_NAME t, COLUMN_NAME c, REFERENCED_TABLE_NAME r, REFERENCED_COLUMN_NAME rc FROM information_schema.KEY_COLUMN_USAGE
            WHERE TABLE_SCHEMA = ? AND REFERENCED_TABLE_NAME IS NOT NULL', [$base]);
        $db->executeStatement('SET FOREIGN_KEY_CHECKS = 0');
        try {
            foreach ($avecClub as $table) {
                $db->executeStatement(\sprintf('DELETE FROM `%s` WHERE club_id = ?', $table), [$club->getId()]);
            }
            $db->executeStatement('DELETE FROM club WHERE id = ?', [$club->getId()]);
            // Tables sans club_id (liaisons) : lignes qui pointent vers une ligne supprimée.
            for ($passe = 0; $passe < 2; ++$passe) {
                foreach ($liens as $l) {
                    if (!\in_array($l['t'], $avecClub, true) && 'club' !== $l['t']) {
                        $db->executeStatement(\sprintf('DELETE FROM `%1$s` WHERE `%2$s` IS NOT NULL AND `%2$s` NOT IN (SELECT `%4$s` FROM `%3$s`)', $l['t'], $l['c'], $l['r'], $l['rc']));
                    }
                }
            }
        } finally {
            $db->executeStatement('SET FOREIGN_KEY_CHECKS = 1');
        }
        $this->em->clear();
    }

    private function ascii(string $texte): string
    {
        return strtolower((string) preg_replace('/[^a-z]/i', '', (string) iconv('UTF-8', 'ASCII//TRANSLIT', $texte)));
    }
}
