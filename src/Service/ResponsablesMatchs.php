<?php

namespace App\Service;

use App\Entity\Categorie;
use App\Entity\Educateur;
use App\Entity\Utilisateur;
use App\Repository\EducateurRepository;
use App\Repository\ResponsableSecteurRepository;
use App\Repository\UtilisateurRepository;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Qui tient les rapports de match (et les convocations) d'une équipe.
 *
 * Responsable : celui choisi par l'administrateur s'il y en a un ; sinon, automatiquement, le ou les éducateurs
 * de l'équipe (fiches Encadrement, « Équipes » cochées) qui ont un compte avec la même adresse e-mail.
 * S'y ajoutent les personnes que le responsable a désignées, et le responsable du secteur (toutes ses équipes). Aucun responsable avec un compte : l'équipe reste
 * ouverte à tout l'encadrement du secteur (pour ne bloquer personne).
 */
class ResponsablesMatchs implements ResetInterface
{
    /** @var array<int, array{comptes: list<Utilisateur>, sans_compte: list<Educateur>, auto: bool, secteur: ?Utilisateur, suspendus: list<Utilisateur>}> */
    private array $cache = [];

    /** @var array<string, Utilisateur>|null responsable de chaque secteur */
    private ?array $secteurs = null;

    public function __construct(
        private readonly EducateurRepository $educateurs,
        private readonly UtilisateurRepository $utilisateurs,
        private readonly ResponsableSecteurRepository $responsablesSecteur,
    ) {
    }

    /** Responsable du secteur de cette équipe (réglé dans Catégories), s'il y en a un. */
    public function responsableSecteur(Categorie $categorie): ?Utilisateur
    {
        $this->secteurs ??= $this->responsablesSecteur->parSecteur();
        $u = $this->secteurs[$categorie->getSecteur()] ?? null;

        // Compte suspendu : il ne tient plus le secteur pendant la suspension.
        return $u && !$u->isSuspendu() ? $u : null;
    }

    /** Ce compte est-il le responsable du secteur de cette équipe ? */
    public function estResponsableSecteur(Categorie $categorie, ?Utilisateur $u): bool
    {
        return null !== $u && $this->responsableSecteur($categorie) === $u;
    }

    /**
     * comptes : responsables (comptes de connexion) ; sans_compte : éducateurs de l'équipe sans compte ;
     * auto : responsables trouvés d'après les fiches Encadrement (pas choisis à la main) ; secteur : responsable du secteur.
     *
     * @return array{comptes: list<Utilisateur>, sans_compte: list<Educateur>, auto: bool, secteur: ?Utilisateur, suspendus: list<Utilisateur>}
     */
    public function de(Categorie $categorie): array
    {
        $id = (int) $categorie->getId();
        if (isset($this->cache[$id])) {
            return $this->cache[$id];
        }
        // Comptes suspendus : ils ne tiennent plus l'équipe pendant la suspension (signalés à part).
        $suspendus = [];
        if (($choisi = $categorie->getResponsableMatchs()) && !$choisi->isSuspendu()) {
            return $this->cache[$id] = ['comptes' => [$choisi], 'sans_compte' => [], 'auto' => false, 'secteur' => $this->responsableSecteur($categorie), 'suspendus' => []];
        }
        if ($choisi) {
            $suspendus[] = $choisi;
        }
        $comptes = $sansCompte = [];
        foreach ($this->educateursDe($categorie) as $e) {
            $compte = $this->compteDe($e);
            if ($compte && $compte->isSuspendu()) {
                $suspendus[$compte->getId()] = $compte;
            } elseif ($compte && \in_array('ROLE_ENCADREMENT', $compte->getRoles(), true)) {
                $comptes[$compte->getId()] = $compte;
            } else {
                $sansCompte[] = $e;
            }
        }

        return $this->cache[$id] = ['comptes' => array_values($comptes), 'sans_compte' => $sansCompte, 'auto' => true, 'secteur' => $this->responsableSecteur($categorie), 'suspendus' => array_values($suspendus)];
    }

    /** @var array<string, Utilisateur>|null comptes du club par prénom et nom */
    private ?array $parNom = null;

    /**
     * Compte de connexion d'une fiche Encadrement : même adresse e-mail ; sinon même prénom et nom
     * (compte créé avec une autre adresse que celle de la fiche).
     */
    public function compteDe(Educateur $e): ?Utilisateur
    {
        if ($e->getEmail() && ($compte = $this->utilisateurs->findOneBy(['email' => mb_strtolower(trim($e->getEmail()))]))) {
            return $compte;
        }
        if (null === $this->parNom) {
            $this->parNom = [];
            foreach ($this->utilisateurs->findAll() as $u) {
                $this->parNom[ResponsableSecteurRepository::nom($u->getPrenom(), $u->getNom())] ??= $u;
            }
        }

        return $this->parNom[ResponsableSecteurRepository::nom($e->getPrenom(), $e->getNom())] ?? null;
    }

    /** Oublie ce qui a été lu (après un changement de responsable, et entre deux requêtes). */
    public function reset(): void
    {
        $this->cache = [];
        $this->secteurs = null;
        $this->parNom = null;
    }

    /** Ce compte est-il un responsable des matchs de l'équipe ? */
    public function estResponsable(Categorie $categorie, ?Utilisateur $u): bool
    {
        return null !== $u && \in_array($u, $this->de($categorie)['comptes'], true);
    }

    /** Ce compte peut-il modifier les matchs de l'équipe (hors administrateur et hors droit du secteur, vérifiés à part) ? */
    public function peutTenir(Categorie $categorie, ?Utilisateur $u): bool
    {
        // Responsable du secteur : toutes les équipes du secteur, même celles qui ont leur éducateur responsable.
        if ([] === $this->de($categorie)['comptes'] || $this->estResponsableSecteur($categorie, $u)) {
            return true;
        }

        return $this->estResponsable($categorie, $u) || (null !== $u && $categorie->getRedacteursMatchs()->contains($u));
    }

    /**
     * Éducateurs actuels de l'équipe (fiches Encadrement qui ont cette équipe cochée).
     *
     * @return list<Educateur>
     */
    public function educateursDe(Categorie $categorie): array
    {
        return $this->educateurs->createQueryBuilder('e')
            ->join('e.equipes', 'c')
            ->andWhere('c = :c')->setParameter('c', $categorie)
            ->andWhere('e.ancien = false')
            ->orderBy('e.nom')->addOrderBy('e.prenom')
            ->getQuery()->getResult();
    }
}
