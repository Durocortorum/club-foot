<?php

namespace App\Twig;

use App\Repository\DemandeAccesMedicalRepository;
use App\Service\AccesMedical;
use App\Service\DemandesMedicales;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/** Dans les gabarits : medical_ouvert() (droit + code valable), medical_jusqu_a() (heure de fermeture), demandes_medicales(). */
class MedicalExtension extends AbstractExtension
{
    public function __construct(
        private readonly AccesMedical $acces,
        private readonly DemandesMedicales $demandes,
        private readonly DemandeAccesMedicalRepository $depot,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('medical_ouvert', $this->acces->estOuvert(...)),
            new TwigFunction('medical_jusqu_a', fn () => $this->acces->estOuvert() ? $this->acces->ouvertJusquA() : null),
            // Demandes d'accès médical en attente, pour la pastille du menu (médecin du club ou administrateur).
            new TwigFunction('demandes_medicales', fn () => $this->demandes->peutTraiter() ? $this->depot->nombreEnAttente() : 0),
        ];
    }
}
