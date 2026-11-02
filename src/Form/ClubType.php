<?php

namespace App\Form;

use App\Entity\Club;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ColorType;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Image;

/**
 * Nom, logo et couleurs du club. Les champs pilotent l'aperçu en direct (apparence_controller.js).
 */
class ClubType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('nom', null, [
                'label' => 'Nom du club',
                'help' => 'Affiché dans l\'en-tête, sur la page de connexion et dans les statistiques. Les matchs déjà saisis restent reconnus.',
                'attr' => ['maxlength' => 80, 'data-apparence-target' => 'nom', 'data-action' => 'apparence#nom'],
            ])
            ->add('couleur', ColorType::class, [
                'label' => 'Couleur principale',
                'help' => 'En-tête, boutons, liens et graphiques.',
                'attr' => ['data-apparence-target' => 'couleur', 'data-action' => 'apparence#apercu'],
            ])
            ->add('couleurTexte', ColorType::class, [
                'label' => 'Couleur du texte sur la couleur principale',
                'help' => 'Blanc en général ; noir si la couleur principale est claire (jaune, ciel…).',
                'attr' => ['data-apparence-target' => 'texte', 'data-action' => 'apparence#apercu'],
            ])
            ->add('fichierLogo', FileType::class, [
                'label' => 'Nouveau logo',
                'mapped' => false,
                'required' => false,
                'help' => 'PNG (de préférence avec fond transparent), JPEG ou WebP, 4 Mo au plus.',
                'constraints' => [new Image(maxSize: '4M', mimeTypes: ['image/png', 'image/jpeg', 'image/webp'], mimeTypesMessage: 'Envoyez une image PNG, JPEG ou WebP.')],
                'attr' => ['accept' => 'image/png,image/jpeg,image/webp', 'data-apparence-target' => 'fichier', 'data-action' => 'apparence#logo'],
            ])
            ->add('logoOrigine', CheckboxType::class, [
                'label' => 'Revenir au logo d\'origine',
                'mapped' => false,
                'required' => false,
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => Club::class]);
    }
}
