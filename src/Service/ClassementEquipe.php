<?php

namespace App\Service;

use App\Entity\Classement;
use App\Entity\Rencontre;
use App\Repository\ClassementRepository;
use App\Repository\RencontreRepository;
use App\Repository\ResultatPouleRepository;

/**
 * Classement de la poule vu depuis un match de championnat (fiche du match) : le classement de la saison, recalculé
 * comme sur la page Championnat (l'officiel copié de la FFF + les matchs joués depuis), avec notre équipe et
 * l'adversaire repérés, et notre place avant et après ce match quand on peut la calculer.
 */
class ClassementEquipe
{
    public function __construct(
        private readonly ClassementRepository $classements,
        private readonly ResultatPouleRepository $resultats,
        private readonly RencontreRepository $rencontres,
        private readonly CalculClassement $calcul,
    ) {
    }

    /**
     * @return array{lignes: list<array<string, mixed>>, mis_a_jour: ?\DateTimeImmutable, recalcule: bool, rang: ?int, rang_avant: ?int}|null
     *                                                                                                                                         null : pas un match de championnat, ou aucun classement saisi
     */
    public function pourLeMatch(Rencontre $match): ?array
    {
        $categorie = $match->getCategorie();
        if ('Championnat' !== $match->getCompetition() || $match->isPlateau() || !$categorie || !$match->getDate()) {
            return null;
        }

        return $this->pourEquipe($categorie, Rencontre::saisonDe($match->getDate()), $match);
    }

    /**
     * Classement de la poule d'une équipe pour une saison (liste des matchs filtrée sur l'équipe) ; avec un match :
     * son adversaire repéré et notre place avant ce match.
     *
     * @return array{lignes: list<array<string, mixed>>, mis_a_jour: ?\DateTimeImmutable, recalcule: bool, rang: ?int, rang_avant: ?int}|null
     */
    public function pourEquipe(\App\Entity\Categorie $categorie, string $saison, ?Rencontre $match = null): ?array
    {
        if ($categorie->isPlateaux()) {
            return null;
        }
        $classement = $this->classements->findOneBy(['categorie' => $categorie, 'saison' => $saison]);
        $resultats = $this->resultats->findBy(['categorie' => $categorie, 'saison' => $saison], ['date' => 'DESC', 'id' => 'ASC']);
        // Comme la page Championnat : sans classement officiel, il se calcule à partir des scores de la poule.
        if (!$classement && $resultats) {
            $classement = (new Classement($categorie, $saison))->setClub($categorie->getClub());
        }
        if (!$classement) {
            return null;
        }
        [$debut, $fin] = Rencontre::bornesSaison($saison);
        $matchs = $this->rencontres->createQueryBuilder('r')
            ->andWhere('r.categorie = :c')->setParameter('c', $categorie)
            ->andWhere('r.competition = :championnat')->setParameter('championnat', 'Championnat')
            ->andWhere('r.date BETWEEN :debut AND :fin')->setParameter('debut', $debut->format('Y-m-d'))->setParameter('fin', $fin->format('Y-m-d'))
            ->getQuery()->getResult();

        $apres = $this->calcul->calculer($classement, $resultats, $matchs);
        $lignes = $apres['lignes'] ?? $classement->getLignes();
        if (!$lignes) {
            return null;
        }
        // Notre place avant ce match : même calcul sans lui (seulement s'il compte dans le recalcul, joué après l'officiel).
        $compte = null !== $match && null !== $match->getResultat() && (!$classement->getMisAJourLe() || $match->getDate()->format('Y-m-d') > $classement->getMisAJourLe()->format('Y-m-d'));
        $avant = $compte ? $this->calcul->calculer($classement, $resultats, array_values(array_filter($matchs, fn (Rencontre $r) => $r !== $match))) : null;
        $lignesAvant = $compte ? ($avant['lignes'] ?? $classement->getLignes()) : null;

        $marquer = fn (array $l) => $l + [
            'nous' => Rencontre::estLeClub($l['equipe']),
            'adversaire' => null !== $match && !Rencontre::estLeClub($l['equipe']) && self::memeEquipe($l['equipe'], $match->getAdversaire()),
        ];
        $lignes = array_map($marquer, $lignes);
        $rang = fn (array $liste) => array_values(array_filter($liste, fn (array $l) => Rencontre::estLeClub($l['equipe'])))[0]['rang'] ?? null;

        return [
            'lignes' => $lignes,
            'mis_a_jour' => $classement->getMisAJourLe(),
            'recalcule' => null !== $apres,
            'rang' => $rang($lignes),
            'rang_avant' => null !== $lignesAvant ? $rang($lignesAvant) : null,
        ];
    }

    private static function memeEquipe(string $nom, ?string $adversaire): bool
    {
        $a = Rencontre::coeurDuNom($nom);
        $b = Rencontre::coeurDuNom($adversaire);

        return '' !== $a && '' !== $b && ($a === $b || str_starts_with($a, $b . ' ') || str_starts_with($b, $a . ' '));
    }
}
