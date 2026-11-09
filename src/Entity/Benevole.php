<?php

namespace App\Entity;

use App\Repository\BenevoleRepository;
use Doctrine\ORM\Mapping as ORM;

/** Bénévole du club (buvette, accueil, transport, arbitre bénévole…) : même fiche que les éducateurs et dirigeants. */
#[ORM\Entity(repositoryClass: BenevoleRepository::class)]
class Benevole extends Intervenant
{
}
