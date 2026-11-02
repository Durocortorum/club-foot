<?php

namespace App\Entity;

use App\Repository\DirigeantRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: DirigeantRepository::class)]
class Dirigeant extends Intervenant
{
}
