<?php

namespace App\Form;

use App\Entity\Conducteur;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class ConducteurType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $date = ['widget' => 'single_text', 'input' => 'datetime_immutable', 'required' => false];
        $builder
            ->add('nom', null, ['label' => 'Prénom et nom', 'help' => 'Écrit comme sur les déplacements, pour les relier.'])
            ->add('telephone', null, ['label' => 'Téléphone', 'required' => false])
            ->add('categoriesPermis', null, ['label' => 'Catégories du permis', 'required' => false, 'attr' => ['placeholder' => 'B, D1…']])
            ->add('numeroPermis', null, ['label' => 'Numéro du permis', 'required' => false])
            ->add('permisExpireLe', DateType::class, ['label' => 'Permis valable jusqu\'au', 'help' => 'Date inscrite sur le permis (format carte : 15 ans).'] + $date)
            ->add('controleLe', DateType::class, ['label' => 'Permis contrôlé le', 'help' => 'Permis vu, sans suspension. Un nouveau contrôle sera demandé ' . Conducteur::VERIFICATION_MOIS . ' mois après.'] + $date)
            ->add('minibus', CheckboxType::class, ['label' => 'Autorisé à conduire le minibus du club', 'required' => false])
            ->add('note', null, ['required' => false]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => Conducteur::class]);
    }
}
