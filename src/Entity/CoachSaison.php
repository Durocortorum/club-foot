<?php

namespace App\Entity;

use App\Repository\CoachSaisonRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Éducateur qui a entraîné une équipe pendant une saison. Access ne l'enregistrait pas :
 * la fiche joueur ne donne que l'éducateur actuel, d'où cette saisie depuis les archives.
 */
#[ORM\Entity(repositoryClass: CoachSaisonRepository::class)]
#[ORM\UniqueConstraint(columns: ['categorie_id', 'saison', 'educateur_id'])]
class CoachSaison implements AppartientAuClub
{
    use ClubTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Categorie $categorie = null;

    /** Saison au format « 2010/2011 ». */
    #[ORM\Column(length: 9)]
    private ?string $saison = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Educateur $educateur = null;

    public function __construct(Categorie $categorie, string $saison, Educateur $educateur)
    {
        $this->categorie = $categorie;
        $this->saison = $saison;
        $this->educateur = $educateur;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCategorie(): ?Categorie
    {
        return $this->categorie;
    }

    public function getSaison(): ?string
    {
        return $this->saison;
    }

    public function getEducateur(): ?Educateur
    {
        return $this->educateur;
    }
}
