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

        return $existants;
    }

    /**
     * Créneau créé ou modifié : les entraînements à venir sans absence sont refaits d'après lui,
     * ceux qui ont déjà des absences gardent leur date mais prennent le nouvel horaire et le nouveau lieu.
     */
    public function creneauModifie(CreneauEntrainement $creneau): void
    {
        $this->em->persist($creneau);
        foreach ($this->futurs($creneau) as $e) {
            if ($e->getAbsences() || $e->isAnnule()) {
                $e->appliquer($creneau);
            } else {
                $this->em->remove($e);
            }
        }
        $this->em->flush();
    }

    /** Créneau supprimé : ses entraînements à venir disparaissent, les passés restent (historique). */
    public function supprimerCreneau(CreneauEntrainement $creneau): void
    {
        foreach ($this->futurs($creneau) as $e) {
            $this->em->remove($e);
        }
        $this->em->remove($creneau);
        $this->em->flush();
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
        $lundi = $e->getDate()->modify('monday this week');
        $semaine = $this->semaines->findOneBy(['categorie' => $e->getCategorie(), 'lundi' => $lundi]);
        if (!$semaine) {
            if (null === $code) {
                return;
            }
            $semaine = (new SemainePresence())->setLundi($lundi)->setCategorie($e->getCategorie())->setClub($e->getClub());
            foreach ($this->joueurs->findBy(['categorie' => $e->getCategorie(), 'ancien' => false], ['nom' => 'ASC']) as $j) {
                $semaine->addPresence((new Presence())->setJoueur($j)->setClub($e->getClub()));
            }
            $this->em->persist($semaine);
        }
        $presence = null;
        foreach ($semaine->getPresences() as $p) {
            if ($p->getJoueur() === $joueur) {
                $presence = $p;
            }
        }
        if (!$presence) {
            if (null === $code) {
                return;
            }
            $semaine->addPresence($presence = (new Presence())->setJoueur($joueur)->setClub($e->getClub()));
        }

        $actuel = $presence->getCreneau($cle);
        $creneaux = $presence->getCreneaux();
        if (null !== $code && (null === $actuel || '' === $actuel || isset(SemainePresence::MOTIFS[$actuel]))) {
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
