<?php

namespace App\Form;

use App\Entity\Joueur;
use App\Entity\Sanction;
use Doctrine\ORM\EntityRepository;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\MoneyType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\LessThan;
use Symfony\Component\Validator\Constraints\PositiveOrZero;

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
            ->add('gravite', ChoiceType::class, ['label' => 'Gravité', 'choices' => array_flip(Sanction::GRAVITES), 'required' => false, 'placeholder' => '—'])
            ->add('amende', MoneyType::class, [
                'label' => 'Amende',
                'required' => false,
                'currency' => 'EUR',
                'html5' => true,
                'attr' => ['min' => 0, 'step' => '0.01', 'placeholder' => 'ex. : 20'],
                'constraints' => [new PositiveOrZero(message: 'Le montant ne peut pas être négatif.'), new LessThan(value: 100000, message: 'Montant trop élevé.')],
                'help' => 'Vide : pas d\'amende.',
            ])
            ->add('amendePayeeLe', DateType::class, [
                'label' => 'Amende payée le',
                'required' => false,
                'widget' => 'single_text',
                'input' => 'datetime_immutable',
                'help' => 'Vide tant qu\'elle n\'est pas payée.',
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => Sanction::class, 'joueur_fixe' => false]);
    }
}
