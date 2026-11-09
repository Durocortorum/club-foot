<?php

namespace App\Entity;

use App\Repository\AdministratifRepository;
use Doctrine\ORM\Mapping as ORM;

/** Personnel administratif du club (secrétariat, comptabilité, intendance…) : même fiche que les éducateurs et dirigeants. */
#[ORM\Entity(repositoryClass: AdministratifRepository::class)]
class Administratif extends Intervenant
{
}
