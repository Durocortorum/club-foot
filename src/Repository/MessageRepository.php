<?php

namespace App\Repository;

use App\Entity\Message;
use App\Entity\MessageDestinataire;
use App\Entity\MessageReponse;
use App\Entity\Utilisateur;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Message>
 */
class MessageRepository extends ServiceEntityRepository
{
    public const PAR_PAGE = 30;

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Message::class);
    }

    /**
     * Messages visibles par un membre de l'encadrement : les siens, et ceux des catégories de ses secteurs
     * (tous pour l'administrateur : secteurs null).
     *
     * @param list<string>|null $secteurs
     *
     * @return list<Message>
     */
    public function pourEncadrement(Utilisateur $moi, ?array $secteurs, int $page = 1): array
    {
        return $this->visibles($moi, $secteurs)
            ->orderBy('m.creeLe', 'DESC')
            ->setFirstResult(($page - 1) * self::PAR_PAGE)->setMaxResults(self::PAR_PAGE)
            ->getQuery()->getResult();
    }

    /** @param list<string>|null $secteurs */
    public function estVisible(Message $message, Utilisateur $moi, ?array $secteurs): bool
    {
        return null === $secteurs || $message->getAuteur() === $moi
            || \in_array($message->getCategorie()?->getSecteur(), $secteurs, true);
    }

    /**
     * Réponses des familles pas encore lues par l'encadrement, dans les messages visibles.
     *
     * @param list<string>|null $secteurs
     */
    public function reponsesNonLues(Utilisateur $moi, ?array $secteurs): int
    {
        return (int) $this->visibles($moi, $secteurs)
            ->select('COUNT(r.id)')
            ->join('m.reponses', 'r')
            ->andWhere('r.deLEncadrement = false')->andWhere('r.lu = false')
            ->getQuery()->getSingleScalarResult();
    }

    /**
     * Messages reçus par un compte famille, du plus récent au plus ancien.
     *
     * @return list<array{message: Message, lu: bool, nouvelles_reponses: int}>
     */
    public function recus(Utilisateur $famille): array
    {
        $lignes = [];
        foreach ($this->getEntityManager()->createQueryBuilder()
            ->select('d', 'm')->from(MessageDestinataire::class, 'd')->join('d.message', 'm')
            ->andWhere('d.compte = :moi')->setParameter('moi', $famille)
            ->orderBy('m.creeLe', 'DESC')
            ->getQuery()->getResult() as $d) {
            $m = $d->getMessage();
            $lignes[$m->getId()] ??= ['message' => $m, 'lu' => true, 'nouvelles_reponses' => 0];
            $lignes[$m->getId()]['lu'] = $lignes[$m->getId()]['lu'] && null !== $d->getLuLe();
        }
        foreach ($this->reponsesEncadrementNonLues($famille) as $r) {
            if (isset($lignes[$r->getMessage()->getId()])) {
                ++$lignes[$r->getMessage()->getId()]['nouvelles_reponses'];
            }
        }

        return array_values($lignes);
    }

    /** Messages non lus et réponses de l'encadrement non lues, pour un compte famille. */
    public function nonLusFamille(Utilisateur $famille): int
    {
        $messages = (int) $this->getEntityManager()->createQueryBuilder()
            ->select('COUNT(DISTINCT IDENTITY(d.message))')->from(MessageDestinataire::class, 'd')
            ->andWhere('d.compte = :moi')->andWhere('d.luLe IS NULL')->setParameter('moi', $famille)
            ->getQuery()->getSingleScalarResult();

        return $messages + \count($this->reponsesEncadrementNonLues($famille));
    }

    /**
     * Informations importantes pas encore lues par ce compte famille (bandeau en tête de l'espace).
     *
     * @return list<Message>
     */
    public function importantsNonLus(Utilisateur $famille): array
    {
        return $this->createQueryBuilder('m')
            ->join('m.destinataires', 'd')
            ->andWhere('d.compte = :moi')->andWhere('d.luLe IS NULL')->andWhere('m.important = true')
            ->setParameter('moi', $famille)
            ->orderBy('m.creeLe', 'DESC')
            ->getQuery()->getResult();
    }

    /** @return list<MessageReponse> */
    private function reponsesEncadrementNonLues(Utilisateur $famille): array
    {
        return $this->getEntityManager()->createQueryBuilder()
            ->select('r')->from(MessageReponse::class, 'r')
            ->andWhere('r.famille = :moi')->andWhere('r.deLEncadrement = true')->andWhere('r.lu = false')
            ->setParameter('moi', $famille)
            ->getQuery()->getResult();
    }

    /** @param list<string>|null $secteurs */
    private function visibles(Utilisateur $moi, ?array $secteurs): QueryBuilder
    {
        $qb = $this->createQueryBuilder('m');
        if (null !== $secteurs) {
            $qb->leftJoin('m.categorie', 'c')
                ->andWhere('m.auteur = :moi OR c.secteur IN (:secteurs)')
                ->setParameter('moi', $moi)->setParameter('secteurs', $secteurs ?: ['-']);
        }

        return $qb;
    }
}
