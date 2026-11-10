<?php

namespace App\Form;

use App\Entity\Categorie;
use App\Entity\Stage;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/** Stage : quoi, pourquoi, quand, où, pour qui, combien, inscriptions. */
class StageType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $date = ['widget' => 'single_text', 'input' => 'datetime_immutable'];
        $builder
            ->add('titre', null, ['label' => 'Titre', 'attr' => ['placeholder' => 'ex. : Stage de la Toussaint U11-U13', 'maxlength' => 120]])
            ->add('raison', ListeOuverteType::class, ['label' => 'Raison', 'suggestions' => Stage::RAISONS])
            ->add('debut', DateType::class, ['label' => 'Premier jour'] + $date)
            ->add('fin', DateType::class, ['label' => 'Dernier jour', 'required' => false, 'help' => 'Vide pour un stage d\'une journée.'] + $date)
            ->add('horaires', null, ['label' => 'Horaires', 'required' => false, 'attr' => ['placeholder' => 'ex. : 9 h – 17 h']])
            ->add('lieu', null, ['label' => 'Lieu', 'required' => false, 'attr' => ['placeholder' => 'ex. : Stade municipal, centre d\'entraînement']])
            ->add('categories', EntityType::class, [
                'label' => 'Équipes concernées',
                'class' => Categorie::class,
                'choices' => $options['categories'],
                'choice_label' => 'nom',
                'multiple' => true,
                'expanded' => true,
                'required' => false,
                'by_reference' => false,
                'help' => $options['tout_le_club'] ? 'Aucune case cochée : le stage concerne tout le club.' : 'Cochez au moins une équipe.',
            ])
            ->add('tarif', TextType::class, [
                'label' => 'Tarif par joueur (€)',
                'required' => false,
                'attr' => ['inputmode' => 'decimal', 'placeholder' => 'Vide si gratuit'],
            ])
            ->add('detailTarif', null, ['label' => 'Précisions sur le tarif', 'required' => false, 'attr' => ['placeholder' => 'ex. : repas compris, 60 € pour le 2e enfant']])
            ->add('places', IntegerType::class, ['label' => 'Nombre de places', 'required' => false, 'attr' => ['min' => 1, 'placeholder' => 'Vide si illimité']])
            ->add('description', TextareaType::class, ['label' => 'Programme, informations', 'required' => false, 'attr' => ['rows' => 5, 'placeholder' => 'Programme, ce qu\'il faut apporter, encadrement…']])
            ->add('inscriptionsFamilles', CheckboxType::class, ['label' => 'Les familles inscrivent leur enfant depuis leur espace', 'required' => false])
            ->add('dateLimite', DateType::class, ['label' => 'Inscriptions jusqu\'au', 'required' => false, 'help' => 'Vide : jusqu\'à la veille du stage.'] + $date);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => Stage::class, 'categories' => [], 'tout_le_club' => false]);
        $resolver->setAllowedTypes('categories', 'array');
        $resolver->setAllowedTypes('tout_le_club', 'bool');
    }
}
