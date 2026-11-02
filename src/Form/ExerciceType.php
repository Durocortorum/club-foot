<?php

namespace App\Form;

use App\Entity\Exercice;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\CallbackTransformer;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Exercice d'une séance. Le schéma est dessiné dans l'éditeur (schema_controller.js)
 * qui le range en JSON dans un champ caché.
 */
class ExerciceType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('titre', null, ['attr' => ['placeholder' => 'ex. : rondo 5 contre 2']])
            ->add('duree', IntegerType::class, ['label' => 'Durée (min)', 'required' => false, 'attr' => ['min' => 1, 'max' => 300, 'class' => 'court']])
            ->add('consignes', TextareaType::class, ['required' => false, 'attr' => ['rows' => 5, 'placeholder' => 'Organisation, déroulement, variantes, points de vigilance…']])
            ->add('schema', HiddenType::class, ['attr' => ['data-schema-target' => 'champ']]);

        $builder->get('schema')->addModelTransformer(new CallbackTransformer(
            fn (?array $schema) => json_encode($schema ?? [], \JSON_UNESCAPED_UNICODE),
            fn (?string $json) => \strlen((string) $json) <= 200_000 && \is_array($s = json_decode((string) $json, true)) ? $s : [],
        ));
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => Exercice::class]);
    }
}
