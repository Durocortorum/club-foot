<?php

namespace App\Entity;

use App\Repository\ArbitreRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Arbitre du club (utile pour les obligations du club envers le district) : la fiche de l'encadrement,
 * plus son niveau et l'équipement fourni.
 */
#[ORM\Entity(repositoryClass: ArbitreRepository::class)]
class Arbitre extends Intervenant
{
    public const NIVEAUX = ['Jeune arbitre', 'District', 'Ligue', 'Fédéral', 'Arbitre assistant', 'Arbitre auxiliaire (bénévole)'];

    #[ORM\Column(length: 40, nullable: true)]
    #[Assert\Length(max: 40)]
    private ?string $niveau = null;

    /** Équipement fourni par le club (tenues, sifflet, cartons, montre…). */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $equipement = null;

    public function getNiveau(): ?string
    {
        return $this->niveau;
    }

    public function setNiveau(?string $niveau): static
    {
        $this->niveau = $niveau;

        return $this;
    }

    public function getEquipement(): ?string
    {
        return $this->equipement;
    }

    public function setEquipement(?string $equipement): static
    {
        $this->equipement = $equipement;

        return $this;
    }
}
