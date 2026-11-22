<?php

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Petit match d'un plateau : adversaire et score du point de vue du club (facultatif, pour mémoire).
 */
class PetitMatchType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('adversaire', TextType::class, ['label' => 'Adversaire', 'required' => false, 'attr' => ['class' => 'majuscules', 'maxlength' => 80]])
            ->add('pour', IntegerType::class, ['label' => 'Nos buts', 'required' => false, 'attr' => ['min' => 0, 'max' => 99, 'inputmode' => 'numeric', 'class' => 'court']])
            ->add('contre', IntegerType::class, ['label' => 'Leurs buts', 'required' => false, 'attr' => ['min' => 0, 'max' => 99, 'inputmode' => 'numeric', 'class' => 'court']]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => null]);
    }
}
