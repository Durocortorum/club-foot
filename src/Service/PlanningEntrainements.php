<?php

namespace App\Service;

use App\Entity\AbsenceEntrainement;
use App\Entity\Categorie;
use App\Entity\CreneauEntrainement;
use App\Entity\Entrainement;
use App\Entity\Joueur;
use App\Entity\Presence;
use App\Entity\SemainePresence;
use App\Repository\CreneauEntrainementRepository;
use App\Repository\EntrainementRepository;
use App\Repository\JoueurRepository;
use App\Repository\SemainePresenceRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Planning des entraînements : les créneaux récurrents de chaque équipe donnent les entraînements
 * des semaines à venir ; les absences signalées sont reportées dans la grille des présences.
 */
class PlanningEntrainements
{
    /**
     * Entraînements préparés à l'avance, en jours : 4 semaines. C'est aussi ce que voient les familles
     * dans leur espace et la période affichée par défaut à l'éducateur (même liste des deux côtés).
     */
    public const HORIZON = 28;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly CreneauEntrainementRepository $creneaux,
        private readonly EntrainementRepository $entrainements,
        private readonly SemainePresenceRepository $semaines,
        private readonly JoueurRepository $joueurs,
    ) {
    }

    /**
     * Crée les entraînements manquants de ces catégories, d'aujourd'hui à l'horizon,
     * et renvoie tous ceux de la période.
     *
     * @param list<Categorie> $categories
     *
     * @return list<Entrainement>
     */
    public function aVenir(array $categories, int $jours = self::HORIZON): array
    {
        [$debut, $fin] = [new \DateTimeImmutable('today'), new \DateTimeImmutable("today +$jours days")];
        $existants = $this->entrainements->entre($categories, $debut, $fin);
        $deja = [];
        foreach ($existants as $e) {
            $deja[$e->getCreneau()?->getId() . '|' . $e->getDate()->format('Y-m-d')] = true;
        }
        $nouveaux = false;
        foreach ($categories ? $this->creneaux->findBy(['categorie' => $categories]) : [] as $c) {
            for ($d = $debut->modify('+' . (($c->getJour() - (int) $debut->format('N') + 7) % 7) . ' days'); $d <= $fin; $d = $d->modify('+7 days')) {
                if (!isset($deja[$c->getId() . '|' . $d->format('Y-m-d')])) {
                    $this->em->persist(Entrainement::depuis($c, $d)->setClub($c->getClub()));
                    $nouveaux = true;
                }
            }
        }
        if ($nouveaux) {
            $this->em->flush();
            $existants = $this->entrainements->entre($categories, $debut, $fin);
        }

        // Supprimés par l'éducateur : masqués, mais gardés pour ne pas être recréés ci-dessus.
        return array_values(array_filter($existants, fn (Entrainement $e) => !$e->isSupprime()));
    }

    /**
     * Créneau créé ou modifié : les entraînements à venir sans absence sont refaits d'après lui,
     * ceux qui ont déjà des absences gardent leur date mais prennent le nouvel horaire et le nouveau lieu.
     */
    public function creneauModifie(CreneauEntrainement $creneau): void
    {
        $this->em->persist($creneau);
        foreach ($this->futurs($creneau) as $e) {
            if ($e->getAbsences() || $e->isAnnule() || $e->isSupprime()) {
                $e->appliquer($creneau);
            } else {
                $this->em->remove($e);
            }
        }
        $this->em->flush();
    }

    /**
     * Créneau supprimé : ses entraînements à venir disparaissent, les passés restent (historique).
     * Les absences signalées par les familles et reportées dans la grille des présences en sont retirées ;
     * une semaine de présences devenue entièrement vide est supprimée.
     */
    public function supprimerCreneau(CreneauEntrainement $creneau): void
    {
        $semaines = [];
        foreach ($this->futurs($creneau) as $e) {
            if ($semaine = $this->retirerAbsencesDeLaGrille($e)) {
                $semaines[spl_object_id($semaine)] = $semaine;
            }
            $this->em->remove($e);
        }
        array_map($this->supprimerSiVide(...), $semaines);
        $this->em->remove($creneau);
        $this->em->flush();
    }

    /**
     * Un seul entraînement supprimé par l'éducateur (sans prévenir les familles) : il disparaît du planning
     * et de l'espace famille ; ses absences signalées sont retirées, de la grille des présences aussi.
     */
    public function supprimerEntrainement(Entrainement $e): void
    {
        $semaine = $this->retirerAbsencesDeLaGrille($e);
        foreach ($e->getAbsences() as $a) {
            $e->retirerAbsence($a);
        }
        $e->supprimer();
        if ($semaine) {
            $this->supprimerSiVide($semaine);
        }
        $this->em->flush();
    }

    /** Vide de la grille les cases venues des absences signalées sur cet entraînement ; renvoie sa semaine. */
    private function retirerAbsencesDeLaGrille(Entrainement $e): ?SemainePresence
    {
        foreach ($e->getAbsences() as $a) {
            $this->reporter($e, $a->getJoueur(), null, $a->getCodeGrille());
        }

        return $this->semaine($e, false);
    }

    /** Semaine de présences sans aucune case remplie : supprimée. */
    private function supprimerSiVide(SemainePresence $semaine): void
    {
        if ($semaine->getPresences()->forAll(fn ($k, Presence $p) => [] === array_filter($p->getCreneaux(), fn ($v) => null !== $v && '' !== $v))) {
            $this->em->remove($semaine);
        }
    }

    /** Signale (ou modifie) une absence et la reporte dans la grille des présences. */
    public function signalerAbsence(Entrainement $e, Joueur $joueur, string $motif, ?string $commentaire, string $par): AbsenceEntrainement
    {
        $absence = $e->absenceDe($joueur);
        $ancienCode = $absence?->getCodeGrille();
        if ($absence) {
            $absence->modifier($motif, $commentaire, $par);
        } else {
            $absence = (new AbsenceEntrainement($e, $joueur, $motif, $commentaire, $par))->setClub($e->getClub());
            $e->ajouterAbsence($absence);
        }
        $this->reporter($e, $joueur, $absence->getCodeGrille(), $ancienCode);
        $this->em->flush();

        return $absence;
    }

    /** Absence retirée (finalement présent) : la case de la grille est vidée si elle venait de cette absence. */
    public function retirerAbsence(AbsenceEntrainement $absence): void
    {
        $e = $absence->getEntrainement();
        $this->reporter($e, $absence->getJoueur(), null, $absence->getCodeGrille());
        $e->retirerAbsence($absence);
        $this->em->flush();
    }

    /**
     * Feuille d'appel d'un entraînement : chaque joueur actif de la catégorie (plus ceux qui ont une absence
     * signalée), son choix actuel et l'absence signalée par la famille. Choix : « present », un code d'absence
     * de la grille (SemainePresence::MOTIFS), ou '' (pas encore noté). Sans rien dans la grille, une absence
     * signalée est proposée d'office.
     *
     * @return list<array{joueur: Joueur, choix: string, absence: ?AbsenceEntrainement}>
     */
    public function appel(Entrainement $e): array
    {
        $cle = $e->getCreneauGrille();
        $grille = [];
        if ($cle && $semaine = $this->semaine($e, false)) {
            foreach ($semaine->getPresences() as $p) {
                $grille[$p->getJoueur()->getId()] = $p->getCreneau($cle);
            }
        }
        $joueurs = [];
        foreach ($this->joueurs->findBy(['categorie' => $e->getCategorie(), 'ancien' => false]) as $j) {
            $joueurs[$j->getId()] = $j;
        }
        foreach ($e->getAbsences() as $a) {
            $joueurs[$a->getJoueur()->getId()] = $a->getJoueur();
        }
        uasort($joueurs, fn (Joueur $a, Joueur $b) => [$a->getNom(), $a->getPrenom()] <=> [$b->getNom(), $b->getPrenom()]);

        $lignes = [];
        foreach ($joueurs as $id => $j) {
            $valeur = $grille[$id] ?? null;
            $absence = $e->absenceDe($j);
            $choix = match (true) {
                null === $valeur || '' === $valeur => $absence?->getCodeGrille() ?? '',
                SemainePresence::estAbsence($valeur) => $valeur,
                default => 'present',
            };
            $lignes[] = ['joueur' => $j, 'choix' => $choix, 'absence' => $absence];
        }

        return $lignes;
    }

    /**
     * Enregistre l'appel dans la grille des présences de la semaine (créée si besoin) :
     * présent = nom de l'équipe, absent = code du motif, '' = case vidée. Un joueur noté présent
     * alors que sa famille l'avait dit absent : l'absence signalée est retirée.
     *
     * @param array<int, string> $choix id du joueur => choix
     *
     * @return int joueurs notés (présents ou absents)
     */
    public function enregistrerAppel(Entrainement $e, array $choix): int
    {
        $cle = $e->getCreneauGrille() ?? throw new \DomainException('La grille des présences n\'a pas de case pour le dimanche.');
        $semaine = $this->semaine($e, true);
        $notes = 0;
        foreach ($this->appel($e) as ['joueur' => $j, 'absence' => $absence]) {
            $c = (string) ($choix[$j->getId()] ?? '');
            if ('present' !== $c && '' !== $c && !SemainePresence::estAbsence($c)) {
                continue;
            }
            $presence = $this->presence($semaine, $j, $e);
            $creneaux = $presence->getCreneaux();
            if ('' === $c) {
                unset($creneaux[$cle]);
            } else {
                $creneaux[$cle] = 'present' === $c ? (string) $e->getCategorie() : $c;
                ++$notes;
            }
            $presence->setCreneaux($creneaux);
            if ('present' === $c && $absence) {
                $e->retirerAbsence($absence);
            }
        }
        $this->em->flush();

        return $notes;
    }

    /** Semaine de présences de l'entraînement ; créée avec l'effectif de la catégorie si $creer. */
    private function semaine(Entrainement $e, bool $creer): ?SemainePresence
    {
        $lundi = $e->getDate()->modify('monday this week');
        $semaine = $this->semaines->findOneBy(['categorie' => $e->getCategorie(), 'lundi' => $lundi]);
        if (!$semaine && $creer) {
            $semaine = (new SemainePresence())->setLundi($lundi)->setCategorie($e->getCategorie())->setClub($e->getClub());
            foreach ($this->joueurs->findBy(['categorie' => $e->getCategorie(), 'ancien' => false], ['nom' => 'ASC']) as $j) {
                $semaine->addPresence((new Presence())->setJoueur($j)->setClub($e->getClub()));
            }
            $this->em->persist($semaine);
        }

        return $semaine;
    }

    /** Ligne du joueur dans la semaine, ajoutée si besoin. */
    private function presence(SemainePresence $semaine, Joueur $joueur, Entrainement $e): Presence
    {
        foreach ($semaine->getPresences() as $p) {
            if ($p->getJoueur() === $joueur) {
                return $p;
            }
        }
        $semaine->addPresence($p = (new Presence())->setJoueur($joueur)->setClub($e->getClub()));

        return $p;
    }

    /**
     * Grille des présences de la semaine : la case du créneau prend le code d'absence ($code),
     * ou est vidée ($code null) si elle contient encore $ancienCode. Une case où l'éducateur a noté
     * le joueur présent (nom d'équipe) n'est jamais écrasée. Semaine créée si besoin, avec l'effectif.
     */
    private function reporter(Entrainement $e, Joueur $joueur, ?string $code, ?string $ancienCode): void
    {
        $cle = $e->getCreneauGrille();
        if (!$cle) {
            return;
        }
        $semaine = $this->semaine($e, null !== $code);
        if (!$semaine) {
            return;
        }
        $presence = $this->presence($semaine, $joueur, $e);

        $actuel = $presence->getCreneau($cle);
        $creneaux = $presence->getCreneaux();
        if (null !== $code && (null === $actuel || '' === $actuel || SemainePresence::estAbsence($actuel))) {
            $creneaux[$cle] = $code;
        } elseif (null === $code && null !== $ancienCode && $actuel === $ancienCode) {
            unset($creneaux[$cle]);
        }
        $presence->setCreneaux($creneaux);
    }

    /** @return list<Entrainement> entraînements de ce créneau, aujourd'hui et après */
    private function futurs(CreneauEntrainement $creneau): array
    {
        if (!$creneau->getId()) {
            return [];
        }

        return $this->entrainements->createQueryBuilder('e')
            ->andWhere('e.creneau = :c')->setParameter('c', $creneau)
            ->andWhere('e.date >= :jour')->setParameter('jour', new \DateTimeImmutable('today'), \Doctrine\DBAL\Types\Types::DATE_IMMUTABLE)
            ->getQuery()->getResult();
    }
}
