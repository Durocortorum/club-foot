<?php

namespace App\Entity;

use App\Repository\MessageRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Message de l'encadrement aux familles (messagerie interne) : à une catégorie, aux convoqués d'un match,
 * à des joueurs choisis ou à tout le club. Chaque famille le lit dans son espace et peut y répondre
 * (fil privé entre elle et l'encadrement, voir MessageReponse).
 */
#[ORM\Entity(repositoryClass: MessageRepository::class)]
#[ORM\Index(columns: ['cree_le'])]
class Message implements AppartientAuClub
{
    use ClubTrait;

    public const CIBLES = [
        'categorie' => 'Une catégorie',
        'convocation' => 'Les convoqués d\'un match',
        'joueurs' => 'Des joueurs choisis',
        'encadrement' => 'Des membres de l\'encadrement',
        'club' => 'Tout le club',
    ];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?Utilisateur $auteur = null;

    /** Nom de l'auteur au moment de l'envoi (gardé si son compte disparaît). */
    #[ORM\Column(length: 120)]
    private string $auteurNom = '';

    #[ORM\Column(length: 120)]
    #[Assert\NotBlank(message: 'Indiquez l\'objet du message.')]
    #[Assert\Length(max: 120)]
    private ?string $sujet = null;

    #[ORM\Column(type: Types::TEXT)]
    #[Assert\NotBlank(message: 'Le message est vide.')]
    #[Assert\Length(max: 5000)]
    private ?string $corps = null;

    /** Information importante : affichée en tête de l'espace famille tant qu'elle n'est pas lue. */
    #[ORM\Column]
    private bool $important = false;

    /** « categorie », « convocation », « joueurs » ou « club ». */
    #[ORM\Column(length: 20)]
    private string $cible = 'categorie';

    /** Destinataires en clair : « U13 », « Convoqués – U13 contre Reims, sam. 15/11 »... */
    #[ORM\Column(length: 160)]
    private string $cibleLibelle = '';

    /** Catégorie concernée (cible catégorie ou convocation, ou joueurs tous de la même catégorie) : qui voit le message côté encadrement. */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?Categorie $categorie = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?Rencontre $rencontre = null;

    #[ORM\Column]
    private \DateTimeImmutable $creeLe;

    /** @var Collection<int, MessageDestinataire> */
    #[ORM\OneToMany(targetEntity: MessageDestinataire::class, mappedBy: 'message', cascade: ['persist'], orphanRemoval: true)]
    private Collection $destinataires;

    /** @var Collection<int, MessageReponse> */
    #[ORM\OneToMany(targetEntity: MessageReponse::class, mappedBy: 'message', cascade: ['persist'], orphanRemoval: true)]
    #[ORM\OrderBy(['creeLe' => 'ASC'])]
    private Collection $reponses;

    public function __construct()
    {
        $this->creeLe = new \DateTimeImmutable();
        $this->destinataires = new ArrayCollection();
        $this->reponses = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getAuteur(): ?Utilisateur
    {
        return $this->auteur;
    }

    public function setAuteur(?Utilisateur $auteur): static
    {
        $this->auteur = $auteur;
        $this->auteurNom = $auteur ? mb_substr((string) $auteur, 0, 120) : $this->auteurNom;

        return $this;
    }

    public function getAuteurNom(): string
    {
        return $this->auteurNom;
    }

    public function getSujet(): ?string
    {
        return $this->sujet;
    }

    public function setSujet(?string $sujet): static
    {
        $this->sujet = null === $sujet ? null : trim($sujet);

        return $this;
    }

    public function getCorps(): ?string
    {
        return $this->corps;
    }

    public function setCorps(?string $corps): static
    {
        $this->corps = null === $corps ? null : trim($corps);

        return $this;
    }

    public function isImportant(): bool
    {
        return $this->important;
    }

    public function setImportant(bool $important): static
    {
        $this->important = $important;

        return $this;
    }

    public function getCible(): string
    {
        return $this->cible;
    }

    public function setCible(string $cible): static
    {
        $this->cible = $cible;

        return $this;
    }

    public function getCibleLibelle(): string
    {
        return $this->cibleLibelle;
    }

    public function setCibleLibelle(string $libelle): static
    {
        $this->cibleLibelle = mb_substr($libelle, 0, 160);

        return $this;
    }

    public function getCategorie(): ?Categorie
    {
        return $this->categorie;
    }

    public function setCategorie(?Categorie $categorie): static
    {
        $this->categorie = $categorie;

        return $this;
    }

    public function getRencontre(): ?Rencontre
    {
        return $this->rencontre;
    }

    public function setRencontre(?Rencontre $rencontre): static
    {
        $this->rencontre = $rencontre;

        return $this;
    }

    public function getCreeLe(): \DateTimeImmutable
    {
        return $this->creeLe;
    }

    /** @return Collection<int, MessageDestinataire> */
    public function getDestinataires(): Collection
    {
        return $this->destinataires;
    }

    public function ajouterDestinataire(?Joueur $joueur, ?Utilisateur $compte): static
    {
        $this->destinataires->add(new MessageDestinataire($this, $joueur, $compte));

        return $this;
    }

    /** @return Collection<int, MessageReponse> */
    public function getReponses(): Collection
    {
        return $this->reponses;
    }

    /** Réponses d'un fil (celui d'un compte famille). @return list<MessageReponse> */
    public function fil(Utilisateur $famille): array
    {
        return array_values($this->reponses->filter(fn (MessageReponse $r) => $r->getFamille() === $famille)->toArray());
    }

    /**
     * Joueurs destinataires, sans doublon, triés par nom.
     *
     * @return list<Joueur>
     */
    public function getJoueurs(): array
    {
        $joueurs = [];
        foreach ($this->destinataires as $d) {
            if ($j = $d->getJoueur()) {
                $joueurs[$j->getId()] = $j;
            }
        }
        usort($joueurs, fn (Joueur $a, Joueur $b) => [$a->getNom(), $a->getPrenom()] <=> [$b->getNom(), $b->getPrenom()]);

        return $joueurs;
    }

    /** Destinataires d'un joueur (un par compte famille ; compte null : pas de compte famille). @return list<MessageDestinataire> */
    public function destinatairesDe(Joueur $joueur): array
    {
        return array_values($this->destinataires->filter(fn (MessageDestinataire $d) => $d->getJoueur() === $joueur)->toArray());
    }

    /** Ce compte famille fait-il partie des destinataires ? */
    public function estDestinataire(Utilisateur $compte): bool
    {
        return $this->destinataires->exists(fn ($i, MessageDestinataire $d) => $d->getCompte() === $compte);
    }

    /** Destinataires membres de l'encadrement (message à des collègues). @return list<MessageDestinataire> */
    public function destinatairesEncadrement(): array
    {
        return array_values($this->destinataires->filter(fn (MessageDestinataire $d) => null === $d->getJoueur())->toArray());
    }

    /** Nombre de destinataires : joueurs (familles) ou membres de l'encadrement. */
    public function nombreDestinataires(): int
    {
        return \count($this->getJoueurs()) + \count($this->destinatairesEncadrement());
    }

    /** Joueurs dont au moins un compte famille a lu le message, plus les membres de l'encadrement qui l'ont lu. */
    public function nombreLus(): int
    {
        return \count(array_filter($this->getJoueurs(), fn (Joueur $j) => array_filter($this->destinatairesDe($j), fn (MessageDestinataire $d) => null !== $d->getLuLe())))
            + \count(array_filter($this->destinatairesEncadrement(), fn (MessageDestinataire $d) => null !== $d->getLuLe()));
    }

    /** Réponses des familles pas encore lues par l'encadrement. */
    public function reponsesNonLues(): int
    {
        return $this->reponses->filter(fn (MessageReponse $r) => !$r->isDeLEncadrement() && !$r->isLu())->count();
    }
}
