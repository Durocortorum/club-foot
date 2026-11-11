<?php

namespace App\Entity;

use App\Repository\ConducteurRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Conducteur autorisé à transporter des joueurs (voiture personnelle, minibus du club). Son permis est
 * contrôlé régulièrement : un nouveau contrôle est demandé VERIFICATION_MOIS mois après le précédent,
 * et signalé ALERTE_JOURS jours avant.
 */
#[ORM\Entity(repositoryClass: ConducteurRepository::class)]
#[ORM\UniqueConstraint(name: 'conducteur_club_nom', columns: ['club_id', 'nom'])]
#[UniqueEntity(fields: ['club', 'nom'], message: 'Ce conducteur est déjà dans la liste.')]
class Conducteur implements AppartientAuClub
{
    use ClubTrait;

    public const VERIFICATION_MOIS = 12;
    public const ALERTE_JOURS = 30;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** Prénom Nom, tel qu'il est noté sur les déplacements. */
    #[ORM\Column(length: 120)]
    #[Assert\NotBlank(message: 'Indiquez le nom du conducteur.')]
    private ?string $nom = null;

    #[ORM\Column(length: 30, nullable: true)]
    private ?string $telephone = null;

    /** Catégories du permis (B, D1…). */
    #[ORM\Column(length: 30, nullable: true)]
    private ?string $categoriesPermis = 'B';

    #[ORM\Column(length: 40, nullable: true)]
    private ?string $numeroPermis = null;

    /** Fin de validité du titre (permis au format carte de crédit : 15 ans). */
    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $permisExpireLe = null;

    /** Dernier contrôle du permis par le club (document vu, pas de suspension). */
    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $controleLe = null;

    #[ORM\Column(length: 120, nullable: true)]
    private ?string $controlePar = null;

    /** Autorisé à conduire le minibus du club. */
    #[ORM\Column(options: ['default' => false])]
    private bool $minibus = false;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $note = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getNom(): ?string
    {
        return $this->nom;
    }

    public function setNom(?string $nom): static
    {
        $this->nom = $nom ? trim(preg_replace('/\s+/u', ' ', $nom) ?? $nom) : null;

        return $this;
    }

    public function getTelephone(): ?string
    {
        return $this->telephone;
    }

    public function setTelephone(?string $telephone): static
    {
        $this->telephone = $telephone ?: null;

        return $this;
    }

    public function getCategoriesPermis(): ?string
    {
        return $this->categoriesPermis;
    }

    public function setCategoriesPermis(?string $categories): static
    {
        $this->categoriesPermis = $categories ? mb_strtoupper(trim($categories)) : null;

        return $this;
    }

    public function getNumeroPermis(): ?string
    {
        return $this->numeroPermis;
    }

    public function setNumeroPermis(?string $numero): static
    {
        $this->numeroPermis = $numero ?: null;

        return $this;
    }

    public function getPermisExpireLe(): ?\DateTimeImmutable
    {
        return $this->permisExpireLe;
    }

    public function setPermisExpireLe(?\DateTimeImmutable $date): static
    {
        $this->permisExpireLe = $date;

        return $this;
    }

    public function getControleLe(): ?\DateTimeImmutable
    {
        return $this->controleLe;
    }

    public function setControleLe(?\DateTimeImmutable $date): static
    {
        $this->controleLe = $date;

        return $this;
    }

    public function getControlePar(): ?string
    {
        return $this->controlePar;
    }

    public function setControlePar(?string $par): static
    {
        $this->controlePar = $par ?: null;

        return $this;
    }

    public function isMinibus(): bool
    {
        return $this->minibus;
    }

    public function setMinibus(bool $minibus): static
    {
        $this->minibus = $minibus;

        return $this;
    }

    public function getNote(): ?string
    {
        return $this->note;
    }

    public function setNote(?string $note): static
    {
        $this->note = $note ?: null;

        return $this;
    }

    /** Date du prochain contrôle : un an après le dernier (aujourd'hui s'il n'a jamais été contrôlé). */
    public function getProchainControle(): \DateTimeImmutable
    {
        return $this->controleLe ? $this->controleLe->modify('+' . self::VERIFICATION_MOIS . ' months') : new \DateTimeImmutable('today');
    }

    /**
     * « expire » (permis plus valable), « a_controler » (jamais contrôlé, ou contrôle trop ancien),
     * « bientot » (contrôle à refaire dans moins de ALERTE_JOURS jours) ou « ok ».
     */
    public function getEtat(): string
    {
        $aujourdhui = new \DateTimeImmutable('today');

        return match (true) {
            null !== $this->permisExpireLe && $this->permisExpireLe < $aujourdhui => 'expire',
            null === $this->controleLe || $this->getProchainControle() <= $aujourdhui => 'a_controler',
            $this->getProchainControle() <= $aujourdhui->modify('+' . self::ALERTE_JOURS . ' days')
                || (null !== $this->permisExpireLe && $this->permisExpireLe <= $aujourdhui->modify('+' . self::ALERTE_JOURS . ' days')) => 'bientot',
            default => 'ok',
        };
    }

    public function isEnRegle(): bool
    {
        return \in_array($this->getEtat(), ['ok', 'bientot'], true);
    }

    public function __toString(): string
    {
        return (string) $this->nom;
    }
}
