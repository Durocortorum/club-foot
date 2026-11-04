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
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
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

        // Minute du but : liste selon la durée de match de la catégorie (contrôleur « score »).
        ChampMinute::poser($builder, 'temps', 'Min.', fn (But $b) => $b->getTemps(), $options['duree'],
            ['class' => 'minute-but', 'data-score-target' => 'butMinute']);

        $builder
            // Camp du but : sert aussi au calcul du score (contrôleur Stimulus « score »).
            ->add('adverse', ChoiceType::class, [
                'label' => 'Pour',
                'choices' => [$this->apparence->club()->getNom() => false, 'Adversaire' => true],
                'choice_attr' => fn (bool $adverse) => ['data-adverse' => $adverse ? '1' : '0'],
                'attr' => ['data-score-target' => 'butCamp'],
            ])
            ->add('buteur', EntityType::class, $joueur)
            ->add('passeur', EntityType::class, ['label' => 'Passeur'] + $joueur)
            // But adverse : seulement le numéro du buteur, à la place des listes de joueurs (contrôleur « score »).
            ->add('numeroAdverse', IntegerType::class, ['label' => 'N°', 'required' => false, 'attr' => [
                'min' => 1, 'max' => 99, 'class' => 'court', 'placeholder' => 'N°', 'title' => 'Numéro du buteur adverse', 'data-score-numero' => '',
            ]])
            ->add('geste', ListeOuverteType::class, ['suggestions' => But::GESTES])
            ->add('conditions', ListeOuverteType::class, ['label' => 'Situation', 'suggestions' => But::CONDITIONS])
            ->add('phase', ChoiceType::class, ['label' => 'Phase', 'choices' => array_flip(But::PHASES), 'required' => false, 'placeholder' => '—']);

        // Un but adverse n'a ni buteur ni passeur du club ; un but du club n'a pas de numéro adverse.
        $builder->addEventListener(FormEvents::SUBMIT, function (FormEvent $event): void {
            $but = $event->getData();
            if ($but instanceof But) {
                $but->isAdverse() ? $but->setButeur(null)->setPasseur(null) : $but->setNumeroAdverse(null);
            }
        });
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        // duree : durée de match de la catégorie du match (voir RencontreType).
        $resolver->setDefaults(['data_class' => But::class, 'joueurs' => [], 'duree' => 90]);
        $resolver->setAllowedTypes('duree', 'int');
    }
}
