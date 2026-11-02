<?php

namespace App\Form;

use App\Entity\BulletinScolaire;
use App\Entity\Joueur;
use Doctrine\ORM\EntityRepository;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\File;

class BulletinScolaireType extends AbstractType
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
            ->add('saison', null, ['required' => false, 'attr' => ['placeholder' => '2025/2026']])
            ->add('libelle', null, ['label' => 'Libellé', 'attr' => ['placeholder' => '1er trimestre']])
            ->add('document', FileType::class, [
                'label' => 'Bulletin (PDF)',
                'mapped' => false,
                'required' => false,
                'attr' => ['accept' => 'application/pdf'],
                'constraints' => [new File(maxSize: '20M', mimeTypes: ['application/pdf'], mimeTypesMessage: 'Le bulletin doit être un fichier PDF.')],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => BulletinScolaire::class, 'joueur_fixe' => false]);
    }
}
