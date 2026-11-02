<?php

namespace App\Form;

use App\Entity\TestPhysique;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class TestPhysiqueType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('date', DateType::class, ['widget' => 'single_text', 'input' => 'datetime_immutable']);
        foreach (TestPhysique::EPREUVES as $champ => [$libelle, $unite]) {
            $builder->add($champ, NumberType::class, [
                'label' => "$libelle ($unite)",
                'required' => false,
                'html5' => true,
                'attr' => ['step' => 0.01],
            ]);
        }
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => TestPhysique::class]);
    }
}
