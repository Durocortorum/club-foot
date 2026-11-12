<?php

namespace App\Form;

use App\Entity\ContactUtile;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\TelType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class ContactUtileType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('rubrique', ChoiceType::class, ['choices' => array_flip(ContactUtile::RUBRIQUES)])
            ->add('libelle', null, ['label' => 'Quoi', 'attr' => ['placeholder' => 'Stade municipal – gardien, comptable, district…']])
            ->add('nom', null, ['label' => 'Nom (personne ou organisme)', 'required' => false])
            ->add('telephone', TelType::class, ['label' => 'Téléphone', 'required' => false])
            ->add('telephone2', TelType::class, ['label' => 'Autre téléphone', 'required' => false])
            ->add('email', EmailType::class, ['label' => 'E-mail', 'required' => false])
            ->add('adresse', null, ['required' => false])
            ->add('note', null, ['label' => 'Remarque', 'required' => false, 'attr' => ['placeholder' => 'Horaires, code du portail…']]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => ContactUtile::class]);
    }
}
