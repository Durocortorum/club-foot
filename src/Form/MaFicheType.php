<?php

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\TelType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Ce que chacun met à jour lui-même sur sa fiche Encadrement (depuis Mon compte) : photo et coordonnées.
 * Le reste (équipes, fonction, diplôme, licence, ancien membre…) reste à l'administrateur.
 */
class MaFicheType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('photo', PhotoType::class)
            ->add('telPortable1', TelType::class, ['label' => 'Portable', 'required' => false])
            ->add('telPortable2', TelType::class, ['label' => 'Portable 2', 'required' => false])
            ->add('telFixe', TelType::class, ['label' => 'Tél. fixe', 'required' => false])
            ->add('telProfessionnel', TelType::class, ['label' => 'Tél. professionnel', 'required' => false])
            ->add('email', EmailType::class, ['label' => 'E-mail de contact', 'required' => false])
            ->add('adresse', null, ['required' => false])
            ->add('codePostal', null, ['label' => 'Code postal', 'required' => false])
            ->add('ville', null, ['required' => false]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        // data_class : celle de la fiche (Educateur, Dirigeant…), donnée par le contrôleur.
        $resolver->setDefaults(['data_class' => null]);
    }
}
