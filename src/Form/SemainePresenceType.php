<?php

namespace App\Form;

use App\Entity\Categorie;
use App\Entity\SemainePresence;
use Doctrine\ORM\EntityRepository;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Création d'une semaine de présence : la grille est ensuite remplie joueur par joueur.
 */
class SemainePresenceType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('lundi', DateType::class, ['label' => 'Semaine du lundi', 'widget' => 'single_text', 'input' => 'datetime_immutable'])
            ->add('categorie', EntityType::class, [
                'class' => Categorie::class,
                'label' => 'Catégorie',
                'placeholder' => '— Choisir —',
                'query_builder' => fn (EntityRepository $r) => $r->createQueryBuilder('c')
                    ->where('c.secteur IN (:s)')->setParameter('s', $options['secteurs'])
                    ->orderBy('c.ordre'),
                'group_by' => fn (Categorie $c) => $c->getSecteurLibelle(),
            ])
            ->add('equipe', null, ['label' => 'Équipe (précision)', 'required' => false, 'attr' => ['placeholder' => 'ex. : U13 Honneur']]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => SemainePresence::class, 'secteurs' => []]);
        $resolver->setAllowedTypes('secteurs', 'array');
    }
}
