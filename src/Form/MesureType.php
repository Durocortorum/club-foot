<?php

namespace App\Form;

use App\Entity\Mesure;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class MesureType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $nombre = fn (string $label, float $pas) => ['label' => $label, 'required' => false, 'html5' => true, 'attr' => ['step' => $pas]];

        $builder
            ->add('date', DateType::class, ['widget' => 'single_text', 'input' => 'datetime_immutable'])
            ->add('poids', NumberType::class, $nombre('Poids (kg)', 0.1))
            ->add('taille', NumberType::class, $nombre('Taille (m)', 0.005))
            ->add('tailleAssis', NumberType::class, $nombre('Taille assis (m)', 0.005))
            ->add('pointure', NumberType::class, $nombre('Pointure', 0.5));
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => Mesure::class]);
    }
}
