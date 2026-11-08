<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * Destinataire d'un message : un joueur et l'un des comptes famille qui le suivent (parent, joueur majeur).
 * Compte null : le joueur n'a pas de compte famille, l'encadrement le prévient autrement (WhatsApp, SMS).
 */
#[ORM\Entity]
#[ORM\Index(columns: ['compte_id', 'lu_le'])]
class MessageDestinataire implements AppartientAuClub
{
    use ClubTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'destinataires')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Message $message;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Joueur $joueur;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'CASCADE')]
    private ?Utilisateur $compte;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $luLe = null;

    public function __construct(Message $message, Joueur $joueur, ?Utilisateur $compte)
    {
        $this->message = $message;
        $this->joueur = $joueur;
        $this->compte = $compte;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getMessage(): Message
    {
        return $this->message;
    }

    public function getJoueur(): Joueur
    {
        return $this->joueur;
    }

    public function getCompte(): ?Utilisateur
    {
        return $this->compte;
    }

    public function getLuLe(): ?\DateTimeImmutable
    {
        return $this->luLe;
    }

    public function marquerLu(): static
    {
        $this->luLe ??= new \DateTimeImmutable();

        return $this;
    }
}
