<?php

namespace App\Form;

use App\Entity\Categorie;
use App\Entity\Evenement;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\DateTimeType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/** Événement du club : quoi, quand, où, pour quelles équipes. */
class EvenementType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('titre', null, ['label' => 'Titre', 'attr' => ['placeholder' => 'ex. : Repas de fin de saison', 'maxlength' => 120]])
            ->add('type', ChoiceType::class, ['label' => 'Type', 'choices' => array_combine(Evenement::TYPES, Evenement::TYPES)])
            ->add('debut', DateTimeType::class, ['label' => 'Début', 'widget' => 'single_text', 'input' => 'datetime_immutable'])
            ->add('fin', DateTimeType::class, ['label' => 'Fin (facultatif)', 'widget' => 'single_text', 'input' => 'datetime_immutable', 'required' => false, 'help' => 'Pour un événement sur plusieurs jours ou pour indiquer l\'heure de fin.'])
            ->add('lieu', null, ['label' => 'Lieu', 'required' => false, 'attr' => ['placeholder' => 'ex. : Club-house, stade municipal']])
            ->add('description', TextareaType::class, ['label' => 'Description', 'required' => false, 'attr' => ['rows' => 5, 'placeholder' => 'Programme, prix, ce qu\'il faut apporter, inscription…']])
            ->add('categories', EntityType::class, [
                'label' => 'Équipes concernées',
                'class' => Categorie::class,
                'choices' => $options['categories'],
                'choice_label' => 'nom',
                'multiple' => true,
                'expanded' => true,
                'required' => false,
                'by_reference' => false,
                'help' => $options['tout_le_club'] ? 'Aucune case cochée : l\'événement concerne tout le club.' : 'Cochez au moins une équipe.',
            ])
            ->add('visibleFamilles', CheckboxType::class, ['label' => 'Visible par les familles dans leur espace', 'required' => false]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => Evenement::class, 'categories' => [], 'tout_le_club' => false]);
        $resolver->setAllowedTypes('categories', 'array');
        $resolver->setAllowedTypes('tout_le_club', 'bool');
    }
}
