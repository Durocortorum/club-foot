<?php

namespace App\Entity;

use App\Repository\ContactUtileRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Numéro utile saisi par le club : stade, comptable, district, mairie, urgences…
 * (le bureau, lui, vient de l'organigramme et des fiches de l'encadrement).
 */
#[ORM\Entity(repositoryClass: ContactUtileRepository::class)]
class ContactUtile implements AppartientAuClub
{
    use ClubTrait;

    /** Rubriques de la page, dans l'ordre d'affichage. */
    public const RUBRIQUES = [
        'club' => 'Club et bureau',
        'installations' => 'Stades et installations',
        'instances' => 'District, ligue, fédération',
        'collectivites' => 'Mairie et collectivités',
        'prestataires' => 'Comptable, assurance et prestataires',
        'transport' => 'Transport',
        'urgences' => 'Urgences et santé',
        'autres' => 'Autres',
    ];

    /** Numéros d'urgence nationaux, affichés d'office en tête de la page. */
    public const URGENCES = [
        ['SAMU', '15'],
        ['Police secours', '17'],
        ['Pompiers', '18'],
        ['Numéro d\'urgence européen', '112'],
        ['Urgences pour sourds et malentendants (SMS)', '114'],
    ];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 20)]
    #[Assert\Choice(callback: [self::class, 'cles'])]
    private string $rubrique = 'autres';

    /** Ce que c'est : « Vice-président », « Stade municipal – gardien », « Comptable »… */
    #[ORM\Column(length: 100)]
    #[Assert\NotBlank(message: 'Indiquez de quoi il s\'agit.')]
    #[Assert\Length(max: 100)]
    private ?string $libelle = null;

    /** Personne ou organisme. */
    #[ORM\Column(length: 100, nullable: true)]
    #[Assert\Length(max: 100)]
    private ?string $nom = null;

    #[ORM\Column(length: 25, nullable: true)]
    #[Assert\Length(max: 25)]
    private ?string $telephone = null;

    #[ORM\Column(length: 25, nullable: true)]
    #[Assert\Length(max: 25)]
    private ?string $telephone2 = null;

    #[ORM\Column(length: 180, nullable: true)]
    #[Assert\Email]
    #[Assert\Length(max: 180)]
    private ?string $email = null;

    #[ORM\Column(length: 200, nullable: true)]
    #[Assert\Length(max: 200)]
    private ?string $adresse = null;

    /** Horaires, code du portail, remarque… */
    #[ORM\Column(length: 255, nullable: true)]
    #[Assert\Length(max: 255)]
    private ?string $note = null;

    /** @return list<string> */
    public static function cles(): array
    {
        return array_keys(self::RUBRIQUES);
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getRubrique(): string
    {
        return $this->rubrique;
    }

    public function setRubrique(string $rubrique): static
    {
        $this->rubrique = $rubrique;

        return $this;
    }

    public function getRubriqueLibelle(): string
    {
        return self::RUBRIQUES[$this->rubrique] ?? $this->rubrique;
    }

    public function getLibelle(): ?string
    {
        return $this->libelle;
    }

    public function setLibelle(?string $libelle): static
    {
        $this->libelle = null === $libelle ? null : trim($libelle);

        return $this;
    }

    public function getNom(): ?string
    {
        return $this->nom;
    }

    public function setNom(?string $nom): static
    {
        $this->nom = null === $nom ? null : (trim($nom) ?: null);

        return $this;
    }

    public function getTelephone(): ?string
    {
        return $this->telephone;
    }

    public function setTelephone(?string $telephone): static
    {
        $this->telephone = null === $telephone ? null : (trim($telephone) ?: null);

        return $this;
    }

    public function getTelephone2(): ?string
    {
        return $this->telephone2;
    }

    public function setTelephone2(?string $telephone2): static
    {
        $this->telephone2 = null === $telephone2 ? null : (trim($telephone2) ?: null);

        return $this;
    }

    public function getEmail(): ?string
    {
        return $this->email;
    }

    public function setEmail(?string $email): static
    {
        $this->email = null === $email ? null : (trim($email) ?: null);

        return $this;
    }

    public function getAdresse(): ?string
    {
        return $this->adresse;
    }

    public function setAdresse(?string $adresse): static
    {
        $this->adresse = null === $adresse ? null : (trim($adresse) ?: null);

        return $this;
    }

    public function getNote(): ?string
    {
        return $this->note;
    }

    public function setNote(?string $note): static
    {
        $this->note = null === $note ? null : (trim($note) ?: null);

        return $this;
    }
}
