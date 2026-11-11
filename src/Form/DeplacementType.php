<?php

namespace App\Form;

use App\Entity\Categorie;
use App\Entity\Deplacement;
use App\Entity\Vehicule;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\MoneyType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\OptionsResolver\OptionsResolver;

class DeplacementType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('date', DateType::class, ['widget' => 'single_text', 'input' => 'datetime_immutable'])
            ->add('objet', null, ['label' => 'Objet', 'attr' => ['placeholder' => 'ex. : Match U15 à Sedan, tournoi de Pâques']])
            ->add('categorie', EntityType::class, ['label' => 'Équipe', 'class' => Categorie::class, 'choices' => $options['categories'], 'required' => false, 'placeholder' => '— Aucune —'])
            ->add('moyen', ChoiceType::class, ['label' => 'Moyen de transport', 'choices' => array_combine(Deplacement::MOYENS, Deplacement::MOYENS)])
            ->add('vehicule', EntityType::class, ['label' => 'Minibus', 'class' => Vehicule::class, 'required' => false, 'placeholder' => '—', 'help' => 'Pour un minibus du club.'])
            ->add('conducteur', ListeOuverteType::class, ['label' => 'Conducteur', 'suggestions' => $options['conducteurs'], 'attr' => ['placeholder' => 'Prénom Nom'], 'help' => 'Choisissez dans la liste des conducteurs (permis contrôlé). Voiture personnelle : les frais sont regroupés par conducteur.'])
            ->add('passagers', IntegerType::class, ['label' => 'Passagers', 'required' => false, 'attr' => ['min' => 0]])
            ->add('km', IntegerType::class, ['label' => 'Kilomètres (aller et retour)', 'required' => false, 'attr' => ['min' => 0]])
            ->add('tauxKm', NumberType::class, ['label' => 'Barème (€ par km)', 'required' => false, 'scale' => 3, 'html5' => true, 'attr' => ['step' => '0.001', 'min' => 0, 'placeholder' => (string) Deplacement::BAREME_KM], 'help' => 'Voiture personnelle. Vide : ' . str_replace('.', ',', (string) Deplacement::BAREME_KM) . ' € (barème des bénévoles).'])
            ->add('frais', MoneyType::class, ['label' => 'Péages, parking ou prix du billet / du car', 'currency' => 'EUR', 'required' => false, 'scale' => 2])
            ->add('note', null, ['required' => false])
            ->addEventListener(FormEvents::POST_SUBMIT, fn (FormEvent $e) => $e->getData()?->normaliser(), 10);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => Deplacement::class, 'categories' => [], 'conducteurs' => []]);
    }
}
