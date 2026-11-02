<?php

namespace App\Form;

use App\Entity\Categorie;
use App\Entity\Seance;
use Doctrine\ORM\EntityRepository;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * En-tête d'une séance d'entraînement ; les exercices s'ajoutent ensuite un par un.
 */
class SeanceType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('date', DateType::class, ['widget' => 'single_text', 'input' => 'datetime_immutable'])
            ->add('categorie', EntityType::class, [
                'class' => Categorie::class,
                'label' => 'Catégorie',
                'placeholder' => '— Choisir —',
                'query_builder' => fn (EntityRepository $r) => $r->createQueryBuilder('c')
                    ->where('c.secteur IN (:s)')->setParameter('s', $options['secteurs'])
                    ->orderBy('c.ordre'),
                'group_by' => fn (Categorie $c) => $c->getSecteurLibelle(),
            ])
            ->add('theme', null, ['label' => 'Thème', 'attr' => ['placeholder' => 'ex. : conservation, jeu en triangle']])
            ->add('objectifs', TextareaType::class, ['required' => false, 'attr' => ['rows' => 3]]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => Seance::class, 'secteurs' => []]);
        $resolver->setAllowedTypes('secteurs', 'array');
    }
}
