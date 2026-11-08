<?php

namespace App\Form;

use App\Entity\Proche;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\TelType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/** Personne à contacter d'un joueur majeur. */
class ProcheType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('nom', TextType::class, ['label' => 'Nom et prénom'])
            ->add('lien', TextType::class, [
                'label' => 'Lien avec le joueur',
                'required' => false,
                'attr' => ['list' => 'liens-proche', 'placeholder' => 'Père, conjoint(e), ami(e)…'],
            ])
            ->add('telephone', TelType::class, ['label' => 'Téléphone', 'required' => false])
            ->add('email', EmailType::class, [
                'label' => 'E-mail',
                'required' => false,
                'help' => 'Facultatif. Avec une adresse, le joueur peut donner à cette personne l\'accès à son espace.',
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => Proche::class]);
    }
}
