<?php

namespace App\Form;

use App\Entity\PosteOrganigramme;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/** Case de l'organigramme : poste, place dans l'arbre, personne qui l'occupe. */
class PosteOrganigrammeType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('titre', null, ['label' => 'Poste', 'attr' => ['placeholder' => 'ex. : Trésorier, Responsable école de foot', 'maxlength' => 80]])
            ->add('parent', EntityType::class, [
                'label' => 'Sous le poste',
                'class' => PosteOrganigramme::class,
                'choices' => $options['parents'],
                'choice_label' => 'titre',
                'required' => false,
                'placeholder' => '— En haut de l\'organigramme —',
            ])
            ->add('personne', ChoiceType::class, [
                'label' => 'Personne (fiche de l\'encadrement)',
                'choices' => $options['personnes'],
                'required' => false,
                'placeholder' => '— Aucune fiche —',
                'help' => 'Sinon, saisissez son nom ci-dessous. Sans personne, le poste est indiqué « à pourvoir ».',
            ])
            ->add('nomLibre', null, ['label' => 'Ou nom de la personne', 'required' => false]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => PosteOrganigramme::class, 'parents' => [], 'personnes' => []]);
        $resolver->setAllowedTypes('parents', 'array');
        $resolver->setAllowedTypes('personnes', 'array');
    }
}
