<?php

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Image;

/**
 * Champ d'envoi de photo, non relié à l'entité : le contrôleur passe le fichier au service Photos.
 */
class PhotoType extends AbstractType
{
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'label' => 'Nouvelle photo',
            'mapped' => false,
            'required' => false,
            'attr' => ['accept' => 'image/jpeg,image/png,image/gif,image/bmp,image/webp'],
            'constraints' => [new Image(maxSize: '15M', mimeTypesMessage: 'Choisissez une image (JPEG, PNG, GIF, BMP ou WebP).')],
        ]);
    }

    public function getParent(): string
    {
        return FileType::class;
    }
}
