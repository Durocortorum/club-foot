<?php

namespace App\Entity;

use App\Repository\ConvocationRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Convocation à un match : joueurs appelés, rendez-vous et message de l'éducateur.
 * Les familles répondent « présent » ou « absent » depuis leur espace (voir Convoque).
 */
#[ORM\Entity(repositoryClass: ConvocationRepository::class)]
class Convocation implements AppartientAuClub
{
    use ClubTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\OneToOne]
    #[ORM\JoinColumn(nullable: false, unique: true, onDelete: 'CASCADE')]
    private ?Rencontre $rencontre = null;

    /** Heure du rendez-vous (ex. : « 13h15 »). */
    #[ORM\Column(length: 10, nullable: true)]
    private ?string $rdvHeure = null;

    #[ORM\Column(length: 120, nullable: true)]
    private ?string $rdvLieu = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $message = null;

    /** Dernier envoi aux familles ; null tant que la convocation n'est pas partie. */
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $envoyeeLe = null;

    /** @var Collection<int, Convoque> */
    #[ORM\OneToMany(targetEntity: Convoque::class, mappedBy: 'convocation', cascade: ['persist'], orphanRemoval: true)]
    private Collection $convoques;

    public function __construct(Rencontre $rencontre)
    {
        $this->rencontre = $rencontre;
        $this->convoques = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getRencontre(): ?Rencontre
    {
        return $this->rencontre;
    }

    public function getRdvHeure(): ?string
    {
        return $this->rdvHeure;
    }

    public function setRdvHeure(?string $rdvHeure): static
    {
        $this->rdvHeure = $rdvHeure;

        return $this;
    }

    public function getRdvLieu(): ?string
    {
        return $this->rdvLieu;
    }

    public function setRdvLieu(?string $rdvLieu): static
    {
        $this->rdvLieu = $rdvLieu;

        return $this;
    }

    public function getMessage(): ?string
    {
        return $this->message;
    }

    public function setMessage(?string $message): static
    {
        $this->message = $message;

        return $this;
    }

    public function getEnvoyeeLe(): ?\DateTimeImmutable
    {
        return $this->envoyeeLe;
    }

    public function marquerEnvoyee(): static
    {
        $this->envoyeeLe = new \DateTimeImmutable();

        return $this;
    }

    /** @return list<Convoque> triés par nom */
    public function getConvoques(): array
    {
        $liste = $this->convoques->toArray();
        usort($liste, fn (Convoque $a, Convoque $b) => [$a->getJoueur()->getNom(), $a->getJoueur()->getPrenom()] <=> [$b->getJoueur()->getNom(), $b->getJoueur()->getPrenom()]);

        return $liste;
    }

    /** @return list<Joueur> */
    public function getJoueurs(): array
    {
        return array_map(fn (Convoque $c) => $c->getJoueur(), $this->getConvoques());
    }

    /**
     * Fixe la liste des joueurs convoqués : les réponses de ceux qui restent sont conservées.
     *
     * @param iterable<Joueur> $joueurs
     */
    public function setJoueurs(iterable $joueurs): static
    {
        $gardes = [];
        foreach ($joueurs as $j) {
            $gardes[$j->getId()] = $j;
        }
        foreach ($this->convoques as $c) {
            if (!isset($gardes[$c->getJoueur()->getId()])) {
                $this->convoques->removeElement($c);
            }
            unset($gardes[$c->getJoueur()->getId()]);
        }
        foreach ($gardes as $j) {
            $this->convoques->add(new Convoque($this, $j));
        }

        return $this;
    }

    /** @return list<Convoque> */
    public function parReponse(?string $reponse): array
    {
        return array_values(array_filter($this->getConvoques(), fn (Convoque $c) => $c->getReponse() === $reponse));
    }

    /** Les familles peuvent répondre jusqu'au jour du match inclus. */
    public function isOuverte(): bool
    {
        return $this->rencontre->getDate() >= new \DateTimeImmutable('today');
    }
}
