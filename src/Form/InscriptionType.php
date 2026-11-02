<?php

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\RepeatedType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Validator\Constraints\Email;
use Symfony\Component\Validator\Constraints\IsTrue;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;

/**
 * Création d'un club et de son premier compte (page publique « Créer mon club »).
 */
class InscriptionType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $requis = new NotBlank(message: 'Champ obligatoire.');
        $builder
            ->add('club', TextType::class, [
                'label' => 'Nom du club',
                'attr' => ['placeholder' => 'ex. : FC Bezannes', 'maxlength' => 80],
                'constraints' => [$requis, new Length(max: 80)],
            ])
            ->add('prenom', TextType::class, ['label' => 'Votre prénom', 'constraints' => [$requis, new Length(max: 80)]])
            ->add('nom', TextType::class, ['label' => 'Votre nom', 'constraints' => [$requis, new Length(max: 80)]])
            ->add('email', EmailType::class, [
                'label' => 'Votre adresse e-mail',
                'help' => 'Elle servira à vous connecter.',
                'constraints' => [$requis, new Email(message: 'Adresse e-mail invalide.'), new Length(max: 180)],
            ])
            ->add('motDePasse', RepeatedType::class, [
                'type' => PasswordType::class,
                'first_options' => ['label' => 'Mot de passe', 'help' => '10 caractères au moins.', 'attr' => ['autocomplete' => 'new-password']],
                'second_options' => ['label' => 'Confirmez le mot de passe', 'attr' => ['autocomplete' => 'new-password']],
                'invalid_message' => 'Les deux mots de passe sont différents.',
                'constraints' => [$requis, new Length(min: 10, minMessage: '10 caractères au moins.', max: 4096)],
            ])
            ->add('conditions', CheckboxType::class, [
                'label' => 'Je suis responsable de ce club (ou mandaté par lui) et j\'accepte que ses données soient hébergées par l\'application.',
                'constraints' => [new IsTrue(message: 'Cette case est obligatoire.')],
            ])
            // Piège à robots : champ invisible qu'un humain laisse vide.
            ->add('site', TextType::class, ['required' => false, 'label' => false, 'attr' => ['tabindex' => -1, 'autocomplete' => 'off', 'class' => 'piege']]);
    }
}
