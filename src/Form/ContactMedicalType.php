<?php

namespace App\Form;

use App\Entity\ContactMedical;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TelType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class ContactMedicalType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('nom')
            ->add('prenom', null, ['label' => 'Prénom', 'required' => false])
            ->add('profession', null, ['required' => false, 'attr' => ['placeholder' => 'Médecin, kiné, cardiologue…']])
            ->add('telephone', TelType::class, ['label' => 'Téléphone', 'required' => false])
            ->add('adresse', null, ['required' => false])
            ->add('codePostal', null, ['label' => 'Code postal', 'required' => false])
            ->add('ville', null, ['required' => false]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => ContactMedical::class]);
    }
}
