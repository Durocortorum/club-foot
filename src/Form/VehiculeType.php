<?php

namespace App\Form;

use App\Entity\Vehicule;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class VehiculeType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $date = ['widget' => 'single_text', 'input' => 'datetime_immutable', 'required' => false];
        $builder
            ->add('nom', null, ['label' => 'Nom', 'attr' => ['placeholder' => 'ex. : Minibus Renault Trafic']])
            ->add('immatriculation', null, ['required' => false])
            ->add('places', IntegerType::class, ['required' => false, 'attr' => ['min' => 1]])
            ->add('kilometrage', IntegerType::class, ['label' => 'Kilométrage', 'required' => false, 'attr' => ['min' => 0]])
            ->add('assurance', DateType::class, ['label' => 'Fin de l\'assurance'] + $date)
            ->add('controleTechnique', DateType::class, ['label' => 'Prochain contrôle technique'] + $date)
            ->add('revision', DateType::class, ['label' => 'Prochaine révision'] + $date)
            ->add('carteCarburant', null, ['label' => 'Carte carburant (n°)', 'required' => false])
            ->add('badgeAutoroute', null, ['label' => 'Badge autoroute (n°)', 'required' => false])
            ->add('note', TextareaType::class, ['required' => false, 'attr' => ['placeholder' => 'Où sont les clés, qui peut le conduire…']]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => Vehicule::class]);
    }
}
