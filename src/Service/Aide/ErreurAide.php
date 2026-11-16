<?php

namespace App\Service\Aide;

/** L'assistant d'aide n'a pas pu répondre (service indisponible, trop de demandes…) : message à montrer tel quel. */
class ErreurAide extends \RuntimeException
{
}
