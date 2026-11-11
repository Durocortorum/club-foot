<?php

namespace App\Entity;

use App\Repository\ResultatTestRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Résultat d'un joueur à un test du catalogue, à une date.
 */
#[ORM\Entity(repositoryClass: ResultatTestRepository::class)]
#[ORM\Index(name: 'resultat_test_joueur_date', columns: ['joueur_id', 'date'])]
class ResultatTest implements AppartientAuClub
{
    use ClubTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Joueur $joueur;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private TypeTest $test;

    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    private \DateTimeImmutable $date;

    #[ORM\Column]
    private float $valeur;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $note = null;

    public function __construct(Joueur $joueur, TypeTest $test, \DateTimeImmutable $date, float $valeur, ?string $note = null)
    {
        $this->joueur = $joueur;
        $this->test = $test;
        $this->date = $date;
        $this->valeur = $valeur;
        $this->note = $note ?: null;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getJoueur(): Joueur
    {
        return $this->joueur;
    }

    public function getTest(): TypeTest
    {
        return $this->test;
    }

    public function getDate(): \DateTimeImmutable
    {
        return $this->date;
    }

    public function getValeur(): float
    {
        return $this->valeur;
    }

    public function setValeur(float $valeur): static
    {
        $this->valeur = $valeur;

        return $this;
    }

    public function getNote(): ?string
    {
        return $this->note;
    }
}
