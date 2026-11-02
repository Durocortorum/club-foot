<?php

namespace App\Form;

use App\Entity\Joueur;
use App\Entity\Participation;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class ParticipationType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('numero', IntegerType::class, ['label' => 'N°', 'required' => false, 'attr' => ['min' => 1, 'max' => 99, 'class' => 'court', 'data-feuille-numero' => '']])
            ->add('joueur', EntityType::class, [
                'class' => Joueur::class,
                'choices' => $options['joueurs'],
                'choice_label' => fn (Joueur $j) => $j->getNom() . ' ' . $j->getPrenom(),
                'placeholder' => '— Joueur —',
                'attr' => ['data-feuille-target' => 'convoque'],
                'choice_attr' => fn (Joueur $j) => ['data-categorie' => $j->getCategorie()?->getId()],
            ])
            ->add('poste', ListeOuverteType::class, ['suggestions' => Participation::POSTES, 'attr' => ['data-feuille-poste' => '']])
            ->add('tempsJeu', IntegerType::class, ['label' => 'Min.', 'required' => false, 'attr' => ['min' => 0, 'max' => 130, 'class' => 'court']])
            ->add('note', NumberType::class, ['required' => false, 'scale' => 1, 'html5' => true, 'attr' => ['step' => 0.5, 'min' => 0, 'max' => 10, 'class' => 'court']])
            ->add('carton', ChoiceType::class, [
                'choices' => array_flip(Participation::CARTONS),
                'choice_label' => fn (string $code) => $code . ' – ' . Participation::CARTONS[$code],
                'required' => false,
                'placeholder' => '—',
            ])
            ->add('appreciation', TextareaType::class, ['label' => 'Appréciation', 'required' => false, 'attr' => ['rows' => 1]]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => Participation::class, 'joueurs' => []]);
    }
}
