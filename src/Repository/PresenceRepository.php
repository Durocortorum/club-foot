<?php

namespace App\Repository;

use App\Entity\Categorie;
use App\Entity\Joueur;
use App\Entity\Presence;
use App\Entity\Rencontre;
use App\Entity\SemainePresence;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Presence>
 */
class PresenceRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Presence::class);
    }

    /**
     * Note et forme de la semaine d'entraînement de chaque joueur avant une date (match) : la plus récente semaine
     * notée qui commence avant cette date, dans les 15 jours qui précèdent.
     *
     * @param iterable<Joueur> $joueurs
     *
     * @return array<int, array{note: ?float, forme: ?string, icone: ?string, libelle: ?string, lundi: string}> par id de joueur
     */
    public function formesAvant(iterable $joueurs, \DateTimeImmutable $date): array
    {
        $joueurs = [...$joueurs];
        if (!$joueurs) {
            return [];
        }
        /** @var list<Presence> $presences */
        $presences = $this->createQueryBuilder('p')->addSelect('s')->join('p.semaine', 's')
            ->andWhere('p.joueur IN (:j)')->setParameter('j', $joueurs)
            ->andWhere('s.lundi <= :d AND s.lundi >= :depuis')->setParameter('d', $date)->setParameter('depuis', $date->modify('-15 days'))
            ->andWhere('p.noteSemaine IS NOT NULL OR p.forme IS NOT NULL')
            ->orderBy('s.lundi', 'ASC')->getQuery()->getResult();
        $formes = [];
        foreach ($presences as $p) {
            $formes[$p->getJoueur()->getId()] = [
                'note' => $p->getNoteSemaine(), 'forme' => $p->getForme(), 'icone' => $p->getFormeIcone(),
                'libelle' => $p->getFormeLibelle(), 'lundi' => $p->getSemaine()->getLundi()->format('d/m'),
            ];
        }

        return $formes;
    }

    /**
     * Présences aux entraînements d'une équipe sur une saison, par joueur inscrit sur ses feuilles.
     *
     * @return array<int, array{id: int, nom: string, prenom: string, poste: string|null, seances: int, presences: int, taux: float|null}>
     */
    public function bilanEquipe(Categorie $categorie, string $saison): array
    {
        [$debut, $fin] = Rencontre::bornesSaison($saison);
        $lignes = $this->createQueryBuilder('p')
            ->select('p.creneaux, j.id, j.nom, j.prenom, j.poste')
            ->join('p.semaine', 's')
            ->join('p.joueur', 'j')
            ->andWhere('s.categorie = :c')
            ->andWhere('s.lundi BETWEEN :debut AND :fin')
            ->setParameter('c', $categorie)
            ->setParameter('debut', $debut->format('Y-m-d'))
            ->setParameter('fin', $fin->format('Y-m-d'))
            ->getQuery()
            ->getArrayResult();

        $bilan = [];
        foreach ($lignes as $l) {
            $b = &$bilan[$l['id']];
            $b ??= ['id' => $l['id'], 'nom' => $l['nom'], 'prenom' => $l['prenom'], 'poste' => $l['poste'], 'seances' => 0, 'presences' => 0, 'taux' => null];
            foreach ($l['creneaux'] as $valeur) {
                ++$b['seances'];
                $b['presences'] += SemainePresence::estAbsence($valeur) ? 0 : 1;
            }
        }
        unset($b);
        foreach ($bilan as &$b) {
            $b['taux'] = $b['seances'] ? round(100 * $b['presences'] / $b['seances']) : null;
        }

        return $bilan;
    }

    /**
     * Toutes les semaines de présence d'un joueur, de la plus ancienne à la plus récente.
     *
     * @return list<Presence>
     */
    public function duJoueur(Joueur $joueur): array
    {
        return $this->createQueryBuilder('p')->addSelect('s')->join('p.semaine', 's')
            ->andWhere('p.joueur = :j')->setParameter('j', $joueur)
            ->orderBy('s.lundi', 'ASC')->getQuery()->getResult();
    }

    /**
     * Bilan des présences d'un joueur par saison, de la plus récente à la plus ancienne.
     *
     * @return array<string, array{seances: int, presences: int, taux: float|null, absences: array<string, int>}>
     */
    public function bilanParSaison(Joueur $joueur): array
    {
        $presences = $this->createQueryBuilder('p')
            ->addSelect('s')
            ->join('p.semaine', 's')
            ->andWhere('p.joueur = :j')
            ->setParameter('j', $joueur)
            ->orderBy('s.lundi', 'DESC')
            ->getQuery()
            ->getResult();

        $bilan = [];
        foreach ($presences as $p) {
            $saison = Rencontre::saisonDe($p->getSemaine()->getLundi());
            $bilan[$saison] ??= ['seances' => 0, 'presences' => 0, 'taux' => null, 'absences' => []];
            foreach ($p->getCreneaux() as $valeur) {
                ++$bilan[$saison]['seances'];
                if (SemainePresence::estAbsence($valeur)) {
                    $bilan[$saison]['absences'][$valeur] = ($bilan[$saison]['absences'][$valeur] ?? 0) + 1;
                } else {
                    ++$bilan[$saison]['presences'];
                }
            }
        }
        foreach ($bilan as &$b) {
            $b['taux'] = $b['seances'] ? round(100 * $b['presences'] / $b['seances']) : null;
            arsort($b['absences']);
        }

        return $bilan;
    }
}
