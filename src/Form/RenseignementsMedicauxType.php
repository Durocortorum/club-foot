<?php

namespace App\Form;

use App\Entity\Joueur;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TelType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/** Renseignements médicaux d'un joueur, saisis depuis son dossier médical (Infirmerie). */
class RenseignementsMedicauxType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('nomAssure', null, ['label' => "Nom de l'assuré", 'required' => false])
            ->add('numeroSecu', null, ['label' => 'N° de sécurité sociale', 'required' => false])
            ->add('medecin', null, ['label' => 'Médecin traitant', 'required' => false])
            ->add('telMedecin', TelType::class, ['label' => 'Tél. du médecin', 'required' => false])
            ->add('renseignementsMedicaux', TextareaType::class, ['label' => 'Renseignements médicaux', 'required' => false]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => Joueur::class]);
    }
}
