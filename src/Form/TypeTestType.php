<?php

namespace App\Form;

use App\Entity\TypeTest;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class TypeTestType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('nom', null, ['label' => 'Nom du test'])
            ->add('famille', ChoiceType::class, ['choices' => array_combine(TypeTest::FAMILLES, TypeTest::FAMILLES)])
            ->add('unite', ListeOuverteType::class, ['label' => 'Unité du résultat', 'suggestions' => ['s', 'km/h', 'm', 'cm', 'nb', 'points', 'palier']])
            ->add('plusBasMieux', ChoiceType::class, ['label' => 'Le meilleur résultat est', 'choices' => ['le plus élevé (distance, vitesse, nombre…)' => false, 'le plus bas (un temps)' => true], 'expanded' => true])
            ->add('ageMin', IntegerType::class, ['label' => 'À partir de (U…)', 'required' => false, 'attr' => ['min' => 5, 'max' => 20, 'placeholder' => 'ex. : 9 pour U9']])
            ->add('ageMax', IntegerType::class, ['label' => 'Jusqu\'à (U…)', 'required' => false, 'attr' => ['min' => 5, 'max' => 20]])
            ->add('objectif', null, ['label' => 'Ce qu\'on mesure', 'required' => false])
            ->add('protocole', TextareaType::class, ['label' => 'Protocole', 'required' => false, 'attr' => ['rows' => 5, 'placeholder' => 'Déroulement, nombre d\'essais, ce qu\'on note…']])
            ->add('materiel', null, ['label' => 'Matériel', 'required' => false]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => TypeTest::class]);
    }
}
