<?php

namespace App\Entity;

use App\Repository\PaiementRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Paiement d'une cotisation (une cotisation peut se payer en plusieurs fois).
 */
#[ORM\Entity(repositoryClass: PaiementRepository::class)]
class Paiement implements AppartientAuClub
{
    use ClubTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'paiements')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Cotisation $cotisation;

    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    private \DateTimeImmutable $date;

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 2)]
    private string $montant;

    #[ORM\Column(length: 30)]
    private string $mode;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $note = null;

    public function __construct(Cotisation $cotisation, \DateTimeImmutable $date, float $montant, string $mode, ?string $note = null)
    {
        $this->cotisation = $cotisation;
        $this->date = $date;
        $this->montant = number_format($montant, 2, '.', '');
        $this->mode = $mode;
        $this->note = $note ?: null;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCotisation(): Cotisation
    {
        return $this->cotisation;
    }

    public function getDate(): \DateTimeImmutable
    {
        return $this->date;
    }

    public function getMontant(): float
    {
        return (float) $this->montant;
    }

    public function getMode(): string
    {
        return $this->mode;
    }

    public function getNote(): ?string
    {
        return $this->note;
    }
}
