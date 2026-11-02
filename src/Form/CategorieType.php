<?php

namespace App\Form;

use App\Entity\Categorie;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;

/**
 * Catégorie (équipe) du club et le secteur qui en donne l'accès aux éducateurs.
 */
class CategorieType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('nom', null, [
                'attr' => ['placeholder' => 'ex. : U15, U15 B, Seniors A, Féminines', 'maxlength' => 30],
                'constraints' => [new NotBlank(message: 'Champ obligatoire.'), new Length(max: 30)],
            ])
            ->add('secteur', ChoiceType::class, [
                'choices' => array_flip(array_map(fn (array $s) => $s['libelle'], Categorie::SECTEURS)),
                'help' => 'Les éducateurs ayant le droit de ce secteur voient et gèrent les joueurs de cette catégorie.',
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => Categorie::class]);
    }
}
