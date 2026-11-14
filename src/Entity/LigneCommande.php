<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/** Ligne d'une commande de la boutique : article, taille, quantité, prix au moment de la commande. */
#[ORM\Entity]
class LigneCommande
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'lignes')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private CommandeBoutique $commande;

    /** Article (vide s'il a été supprimé depuis ; le nom reste dans $libelle). */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?ArticleBoutique $article;

    #[ORM\Column(length: 120)]
    private string $libelle;

    #[ORM\Column(length: 20)]
    private string $taille;

    #[ORM\Column]
    private int $quantite;

    /** Prix unitaire, en centimes. */
    #[ORM\Column]
    private int $prixUnitaire;

    public function __construct(CommandeBoutique $commande, ArticleBoutique $article, string $taille, int $quantite)
    {
        $this->commande = $commande;
        $this->article = $article;
        $this->libelle = (string) $article->getNom();
        $this->taille = mb_substr($taille, 0, 20);
        $this->quantite = max(1, $quantite);
        $this->prixUnitaire = (int) round($article->getPrix() * 100);
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCommande(): CommandeBoutique
    {
        return $this->commande;
    }

    public function getArticle(): ?ArticleBoutique
    {
        return $this->article;
    }

    public function getLibelle(): string
    {
        return $this->libelle;
    }

    public function getTaille(): string
    {
        return $this->taille;
    }

    public function getQuantite(): int
    {
        return $this->quantite;
    }

    public function getPrixUnitaire(): int
    {
        return $this->prixUnitaire;
    }

    public function getMontant(): int
    {
        return $this->prixUnitaire * $this->quantite;
    }
}
