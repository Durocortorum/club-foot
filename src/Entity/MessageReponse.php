<?php

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Réponse dans le fil privé entre une famille (compte famille) et l'encadrement, sous un message.
 * Les autres familles ne la voient pas.
 */
#[ORM\Entity]
class MessageReponse implements AppartientAuClub
{
    use ClubTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'reponses')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Message $message;

    /** Compte famille du fil. */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Utilisateur $famille;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?Utilisateur $auteur;

    #[ORM\Column(length: 120)]
    private string $auteurNom;

    /** Écrite par l'encadrement (sinon par la famille). */
    #[ORM\Column]
    private bool $deLEncadrement;

    #[ORM\Column(type: Types::TEXT)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 3000)]
    private string $corps;

    #[ORM\Column]
    private \DateTimeImmutable $creeLe;

    /** Lue par le destinataire : l'encadrement pour une réponse de la famille, la famille sinon. */
    #[ORM\Column]
    private bool $lu = false;

    public function __construct(Message $message, Utilisateur $famille, Utilisateur $auteur, string $corps)
    {
        $this->message = $message;
        $this->famille = $famille;
        $this->auteur = $auteur;
        $this->auteurNom = mb_substr($auteur->isFamille() ? (string) $auteur->getEmail() : (string) $auteur, 0, 120);
        $this->deLEncadrement = !$auteur->isFamille();
        $this->corps = trim($corps);
        $this->creeLe = new \DateTimeImmutable();
        $message->getReponses()->add($this);
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getMessage(): Message
    {
        return $this->message;
    }

    public function getFamille(): Utilisateur
    {
        return $this->famille;
    }

    public function getAuteur(): ?Utilisateur
    {
        return $this->auteur;
    }

    public function getAuteurNom(): string
    {
        return $this->auteurNom;
    }

    public function isDeLEncadrement(): bool
    {
        return $this->deLEncadrement;
    }

    public function getCorps(): string
    {
        return $this->corps;
    }

    public function getCreeLe(): \DateTimeImmutable
    {
        return $this->creeLe;
    }

    public function isLu(): bool
    {
        return $this->lu;
    }

    public function marquerLue(): static
    {
        $this->lu = true;

        return $this;
    }
}
