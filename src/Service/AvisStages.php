<?php

namespace App\Service;

use App\Entity\Joueur;
use App\Entity\Message;
use App\Entity\Stage;
use App\Entity\Utilisateur;
use App\Repository\JoueurRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Stages : joueurs concernés, inscription d'office des stages obligatoires, et message aux joueurs
 * (dans leur espace et par e-mail : le joueur majeur sur son compte, les parents d'un mineur) par la messagerie.
 */
class AvisStages
{
    private const JOURS = ['dimanche', 'lundi', 'mardi', 'mercredi', 'jeudi', 'vendredi', 'samedi'];

    public function __construct(
        private readonly JoueurRepository $joueurs,
        private readonly Messagerie $messagerie,
        private readonly EntityManagerInterface $em,
    ) {
    }

    /**
     * Joueurs en activité des équipes du stage (tous si tout le club).
     *
     * @return list<Joueur>
     */
    public function concernes(Stage $stage): array
    {
        $qb = $this->joueurs->createQueryBuilder('j')->leftJoin('j.categorie', 'c')->addSelect('c')
            ->where('j.ancien = false')->orderBy('c.ordre')->addOrderBy('j.nom')->addOrderBy('j.prenom');
        if (!$stage->isToutLeClub()) {
            $qb->andWhere('j.categorie IN (:categories)')->setParameter('categories', $stage->getCategories());
        } else {
            $qb->andWhere('j.categorie IS NOT NULL');
        }

        return $qb->getQuery()->getResult();
    }

    /** Stage obligatoire : inscrit d'office les joueurs concernés qui ne le sont pas encore. Renvoie le nombre ajouté. */
    public function inscrireTous(Stage $stage): int
    {
        $ajoutes = 0;
        foreach ($this->concernes($stage) as $j) {
            if (!$stage->inscription($j)) {
                $this->em->persist($stage->inscrire($j));
                ++$ajoutes;
            }
        }
        $this->em->flush();

        return $ajoutes;
    }

    /**
     * Message aux joueurs concernés (obligatoire : tous ; sinon : tous, pour qu'ils s'inscrivent).
     * Ouvre les inscriptions depuis l'espace pour un stage non obligatoire.
     *
     * @return array{joueurs: int, emails: int, sans_compte: list<Joueur>, echecs: list<string>}
     */
    public function prevenir(Stage $stage, Utilisateur $par): array
    {
        if (!$stage->isObligatoire()) {
            $stage->setInscriptionsFamilles(true);
        }
        $joueurs = $this->concernes($stage);
        $message = (new Message())
            ->setAuteur($par)
            ->setSujet(($stage->isObligatoire() ? 'Stage obligatoire : ' : 'Stage : ') . $stage->getTitre())
            ->setCorps($this->corps($stage))
            ->setImportant($stage->isObligatoire())
            ->setCible('joueurs')
            ->setCibleLibelle($stage->isToutLeClub() ? 'Tout le club' : implode(', ', $stage->getCategories()->map(fn ($c) => $c->getNom())->toArray()))
            ->setCategorie(1 === $stage->getCategories()->count() ? $stage->getCategories()->first() : null);
        $resultat = $this->messagerie->envoyer($message, $joueurs, true);
        $stage->setPrevenuLe(new \DateTimeImmutable());
        $this->em->flush();

        return ['joueurs' => \count($joueurs)] + $resultat;
    }

    private function corps(Stage $s): string
    {
        $quand = $s->getFin() && $s->getFin() != $s->getDebut()
            ? 'du ' . $this->jour($s->getDebut()) . ' au ' . $this->jour($s->getFin())
            : 'le ' . $this->jour($s->getDebut());
        $lignes = [
            ($s->isObligatoire() ? 'Le club organise un stage obligatoire ' : 'Le club organise un stage ') . $quand
                . ($s->getHoraires() ? ' (' . $s->getHoraires() . ')' : '')
                . ($s->getLieu() ? ', ' . $s->getLieu() : '') . '.',
        ];
        $infos = array_filter([
            $s->getRaison() ? 'Raison : ' . $s->getRaison() : null,
            'Tarif : ' . ($s->isPayant() ? number_format((float) $s->getTarif(), fmod((float) $s->getTarif(), 1) ? 2 : 0, ',', ' ') . ' €' : 'gratuit') . ($s->getDetailTarif() ? ' (' . $s->getDetailTarif() . ')' : ''),
            $s->getPlaces() && !$s->isObligatoire() ? 'Places : ' . $s->getPlaces() : null,
        ]);
        $lignes[] = implode("\n", $infos);
        if ($s->getDescription()) {
            $lignes[] = $s->getDescription();
        }
        $lignes[] = $s->isObligatoire()
            ? 'Tous les joueurs concernés y sont inscrits. En cas d\'empêchement, répondez à ce message.'
            : 'Pour participer, inscrivez-vous depuis votre espace (rubrique Stages) avant le ' . $this->jour($s->getLimiteInscription()) . '.';

        return implode("\n\n", $lignes);
    }

    private function jour(\DateTimeImmutable $d): string
    {
        return self::JOURS[(int) $d->format('w')] . ' ' . $d->format('d/m/Y');
    }
}
