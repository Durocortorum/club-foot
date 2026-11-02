<?php

namespace App\Form;

use App\Entity\Utilisateur;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\RepeatedType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;

/**
 * Compte utilisateur, géré par un administrateur : identité, droits et mot de passe.
 */
class UtilisateurType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('prenom', null, ['label' => 'Prénom'])
            ->add('nom')
            ->add('email', EmailType::class, ['label' => 'Adresse e-mail', 'help' => 'Utilisée pour se connecter.'])
            ->add('rolesAttribues', ChoiceType::class, [
                'label' => 'Droits',
                'choices' => array_flip(Utilisateur::ROLES),
                'expanded' => true,
                'multiple' => true,
                'help' => 'Un secteur (École de foot, Formation...) donne accès à ses joueurs, matchs et présences. « Administration » donne tous les droits.',
                // Un administrateur ne peut pas se retirer ses propres droits d'administration.
                'choice_attr' => fn (string $role) => $options['soi_meme'] && 'ROLE_ADMIN' === $role ? ['disabled' => 'disabled'] : [],
            ])
            ->add('motDePasse', RepeatedType::class, [
                'type' => PasswordType::class,
                'mapped' => false,
                'required' => $options['mot_de_passe_obligatoire'],
                'first_options' => [
                    'label' => $options['mot_de_passe_obligatoire'] ? 'Mot de passe' : 'Nouveau mot de passe',
                    'help' => $options['mot_de_passe_obligatoire'] ? '8 caractères minimum.' : 'Laisser vide pour ne pas le changer. 8 caractères minimum.',
                    'attr' => ['autocomplete' => 'new-password'],
                ],
                'second_options' => ['label' => 'Confirmation du mot de passe', 'attr' => ['autocomplete' => 'new-password']],
                'invalid_message' => 'Les deux mots de passe ne sont pas identiques.',
                'constraints' => array_filter([
                    $options['mot_de_passe_obligatoire'] ? new NotBlank(message: 'Choisissez un mot de passe.') : null,
                    new Length(min: 8, max: 4096, minMessage: 'Le mot de passe doit faire au moins {{ limit }} caractères.'),
                ]),
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Utilisateur::class,
            'mot_de_passe_obligatoire' => false,
            'soi_meme' => false,
        ]);
    }
}
