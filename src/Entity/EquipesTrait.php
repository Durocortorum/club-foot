<?php

namespace App\Entity;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * Équipes (catégories du club) dont s'occupe un éducateur ou un dirigeant.
 * Table de liaison propre à chaque entité (educateur_categorie, dirigeant_categorie).
 */
trait EquipesTrait
{
    /** @var Collection<int, Categorie>|null */
    #[ORM\ManyToMany(targetEntity: Categorie::class)]
    #[ORM\OrderBy(['ordre' => 'ASC', 'nom' => 'ASC'])]
    private ?Collection $equipes = null;

    /** @return Collection<int, Categorie> */
    public function getEquipes(): Collection
    {
        return $this->equipes ??= new ArrayCollection();
    }

    public function addEquipe(Categorie $categorie): static
    {
        if (!$this->getEquipes()->contains($categorie)) {
            $this->getEquipes()->add($categorie);
        }

        return $this;
    }

    public function removeEquipe(Categorie $categorie): static
    {
        $this->getEquipes()->removeElement($categorie);

        return $this;
    }
}
