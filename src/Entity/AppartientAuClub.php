<?php

namespace App\Entity;

/**
 * Donnée propre à un club. Toutes les lectures sont limitées au club de l'utilisateur connecté
 * (App\Doctrine\ClubFilter) et chaque nouvelle donnée lui est rattachée (App\Doctrine\ClubListener).
 */
interface AppartientAuClub
{
    public function getClub(): ?Club;

    public function setClub(Club $club): static;
}
