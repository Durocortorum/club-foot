<?php

namespace App\Form;

use App\Entity\Blessure;
use App\Entity\Joueur;
use Doctrine\ORM\EntityRepository;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class BlessureType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $date = ['widget' => 'single_text', 'input' => 'datetime_immutable', 'required' => false];

        $builder
            ->add('joueur', EntityType::class, [
                'class' => Joueur::class,
                'placeholder' => '— Choisir —',
                'choice_label' => fn (Joueur $j) => sprintf('%s %s (%s)', $j->getNom(), $j->getPrenom(), $j->getCategorie() ?? '?'),
                'query_builder' => fn (EntityRepository $r) => $r->createQueryBuilder('j')
                    ->where('j.ancien = false')->orderBy('j.nom')->addOrderBy('j.prenom'),
                'disabled' => $options['joueur_fixe'],
            ])
            ->add('date', DateType::class, ['label' => 'Date de la blessure'] + $date)
            ->add('dateReprise', DateType::class, ['label' => 'Date de reprise', 'help' => 'Laisser vide tant que le joueur est indisponible.'] + $date)
            ->add('type', ListeOuverteType::class, ['label' => 'Type de blessure', 'suggestions' => Blessure::TYPES])
            ->add('zone', null, ['label' => 'Zone touchée', 'required' => false, 'attr' => ['placeholder' => 'ex. : Ligament croisé antérieur']])
            ->add('localisation', null, ['required' => false, 'attr' => ['placeholder' => 'ex. : Genou gauche']])
            ->add('membre', ChoiceType::class, ['choices' => array_combine(Blessure::MEMBRES, Blessure::MEMBRES), 'required' => false, 'placeholder' => '—'])
            ->add('lieu', ListeOuverteType::class, ['label' => 'Circonstances', 'suggestions' => Blessure::LIEUX])
            ->add('avecArret', ChoiceType::class, ['label' => 'Arrêt', 'choices' => ['Avec arrêt' => true, 'Sans arrêt' => false], 'required' => false, 'placeholder' => '—'])
            ->add('equipe', null, ['label' => 'Équipe au moment de la blessure', 'required' => false])
            ->add('commentaire', TextareaType::class, ['required' => false]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => Blessure::class, 'joueur_fixe' => false]);
    }
}
