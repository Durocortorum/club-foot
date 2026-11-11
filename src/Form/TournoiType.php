<?php

namespace App\Form;

use App\Entity\Categorie;
use App\Entity\Tournoi;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\MoneyType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class TournoiType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $date = ['widget' => 'single_text', 'input' => 'datetime_immutable'];
        $builder
            ->add('nom', null, ['label' => 'Nom du tournoi', 'attr' => ['placeholder' => 'ex. : Tournoi de Pâques de Tinqueux']])
            ->add('categorie', EntityType::class, ['label' => 'Équipe', 'class' => Categorie::class, 'choices' => $options['categories'], 'placeholder' => '— Choisir —'])
            ->add('debut', DateType::class, ['label' => 'Date'] + $date)
            ->add('fin', DateType::class, ['label' => 'Jusqu\'au', 'required' => false, 'help' => 'S\'il dure plusieurs jours.'] + $date)
            ->add('lieu', null, ['required' => false, 'attr' => ['placeholder' => 'Stade, ville']])
            ->add('organisateur', null, ['required' => false, 'attr' => ['placeholder' => 'Club organisateur, contact']])
            ->add('format', null, ['label' => 'Format', 'required' => false, 'attr' => ['placeholder' => 'ex. : 4 poules de 4, puis classement ; 2 × 10 min']])
            ->add('transport', ListeOuverteType::class, ['required' => false, 'suggestions' => Tournoi::TRANSPORTS])
            ->add('detailTransport', null, ['label' => 'Départ, retour, conducteurs', 'required' => false, 'attr' => ['placeholder' => 'ex. : départ 8h parking du stade, retour vers 18h']])
            ->add('coutInscription', MoneyType::class, ['label' => 'Coût de l\'inscription', 'currency' => 'EUR', 'required' => false, 'scale' => 2])
            ->add('resultat', null, ['label' => 'Résultat final', 'required' => false, 'attr' => ['placeholder' => 'ex. : Vainqueur, 3e sur 16, quart de finale']])
            ->add('description', TextareaType::class, ['label' => 'Informations', 'required' => false, 'attr' => ['placeholder' => 'Programme, tenue, repas, contacts…']]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => Tournoi::class, 'categories' => []]);
    }
}
