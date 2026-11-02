<?php

namespace App\Form;

use App\Entity\Joueur;
use App\Entity\Sanction;
use Doctrine\ORM\EntityRepository;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class SanctionType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('joueur', EntityType::class, [
                'class' => Joueur::class,
                'placeholder' => '— Choisir —',
                'choice_label' => fn (Joueur $j) => sprintf('%s %s (%s)', $j->getNom(), $j->getPrenom(), $j->getCategorie() ?? '?'),
                'query_builder' => fn (EntityRepository $r) => $r->createQueryBuilder('j')
                    ->where('j.ancien = false')->orderBy('j.nom')->addOrderBy('j.prenom'),
                'disabled' => $options['joueur_fixe'],
            ])
            ->add('date', DateType::class, ['widget' => 'single_text', 'input' => 'datetime_immutable'])
            ->add('lieu', ListeOuverteType::class, ['suggestions' => Sanction::LIEUX])
            ->add('motif')
            ->add('sanctionSportive', null, ['label' => 'Sanction sportive', 'required' => false, 'attr' => ['placeholder' => '1 match de suspension']])
            ->add('sanctionScolaire', null, ['label' => 'Sanction scolaire', 'required' => false, 'attr' => ['placeholder' => 'Colle, exclusion de cours…']])
            ->add('gravite', ChoiceType::class, ['label' => 'Gravité', 'choices' => array_flip(Sanction::GRAVITES), 'required' => false, 'placeholder' => '—']);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => Sanction::class, 'joueur_fixe' => false]);
    }
}
