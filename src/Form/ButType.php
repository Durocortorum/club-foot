<?php

namespace App\Form;

use App\Entity\But;
use App\Entity\Joueur;
use App\Service\Apparence;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class ButType extends AbstractType
{
    public function __construct(private readonly Apparence $apparence)
    {
    }

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
            ->add('minute', IntegerType::class, ['label' => 'Min.', 'required' => false, 'attr' => ['min' => 0, 'max' => 130, 'class' => 'court']])
            ->add('adverse', ChoiceType::class, ['label' => 'Pour', 'choices' => [$this->apparence->club()->getNom() => false, 'Adversaire' => true]])
            ->add('buteur', EntityType::class, $joueur)
            ->add('passeur', EntityType::class, ['label' => 'Passeur'] + $joueur)
            ->add('geste', ListeOuverteType::class, ['suggestions' => But::GESTES])
            ->add('conditions', ListeOuverteType::class, ['label' => 'Situation', 'suggestions' => But::CONDITIONS])
            ->add('phase', ChoiceType::class, ['label' => 'Phase', 'choices' => array_flip(But::PHASES), 'required' => false, 'placeholder' => '—']);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => But::class, 'joueurs' => []]);
    }
}
