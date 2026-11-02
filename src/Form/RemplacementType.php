<?php

namespace App\Form;

use App\Entity\Joueur;
use App\Entity\Remplacement;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class RemplacementType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $joueur = [
            'class' => Joueur::class,
            'choices' => $options['joueurs'],
            'choice_label' => fn (Joueur $j) => $j->getNom() . ' ' . $j->getPrenom(),
            'required' => false,
            'placeholder' => '—',
            // Limité dans la page aux joueurs de la feuille de match (contrôleur Stimulus « feuille »).
            'attr' => ['data-feuille-target' => 'joueur'],
            'choice_attr' => fn (Joueur $j) => ['data-categorie' => $j->getCategorie()?->getId()],
        ];

        $builder
            ->add('minute', IntegerType::class, ['label' => 'Min.', 'required' => false, 'attr' => ['min' => 0, 'max' => 130, 'class' => 'court', 'data-feuille-minute' => '']])
            ->add('sortant', EntityType::class, ['label' => 'Sort', 'attr' => $joueur['attr'] + ['data-feuille-sens' => 'sortant']] + $joueur)
            ->add('entrant', EntityType::class, ['label' => 'Entre', 'attr' => $joueur['attr'] + ['data-feuille-sens' => 'entrant']] + $joueur)
            ->add('motif', ChoiceType::class, ['choices' => array_combine(Remplacement::MOTIFS, Remplacement::MOTIFS), 'required' => false, 'placeholder' => '—']);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => Remplacement::class, 'joueurs' => []]);
    }
}
