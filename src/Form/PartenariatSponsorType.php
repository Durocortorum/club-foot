<?php

namespace App\Form;

use App\Entity\PartenariatSponsor;
use App\Entity\Rencontre;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\MoneyType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/** Participation d'un sponsor pour une saison. */
class PartenariatSponsorType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        // Saisons proposées : la suivante et les 6 précédentes.
        $courante = (int) substr(Rencontre::saisonDe(new \DateTimeImmutable('today')), 0, 4);
        $saisons = [];
        for ($a = $courante + 1; $a >= $courante - 6; --$a) {
            $saisons["$a/" . ($a + 1)] = "$a/" . ($a + 1);
        }

        $builder
            ->add('saison', ChoiceType::class, ['label' => 'Saison', 'choices' => $saisons])
            ->add('nature', ChoiceType::class, ['label' => 'Nature', 'choices' => array_combine(PartenariatSponsor::NATURES, PartenariatSponsor::NATURES)])
            ->add('montant', MoneyType::class, ['label' => 'Montant (ou valeur estimée)', 'currency' => 'EUR', 'required' => false, 'scale' => 2])
            ->add('description', null, ['label' => 'Détail', 'required' => false, 'attr' => ['placeholder' => 'ex. : jeux de maillots U13, panneau au stade']])
            ->add('contratDebut', DateType::class, ['label' => 'Début du contrat', 'widget' => 'single_text', 'input' => 'datetime_immutable', 'required' => false])
            ->add('contratFin', DateType::class, ['label' => 'Fin du contrat', 'widget' => 'single_text', 'input' => 'datetime_immutable', 'required' => false]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => PartenariatSponsor::class]);
    }
}
