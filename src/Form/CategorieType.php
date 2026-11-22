<?php

namespace App\Form;

use App\Entity\Categorie;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;

/**
 * Catégorie (équipe) du club et le secteur qui en donne l'accès aux éducateurs.
 */
class CategorieType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('nom', null, [
                'attr' => ['placeholder' => 'ex. : U15, U15 B, Seniors A, Féminines', 'maxlength' => 30],
                'constraints' => [new NotBlank(message: 'Champ obligatoire.'), new Length(max: 30)],
            ])
            ->add('secteur', ChoiceType::class, [
                'choices' => array_flip(array_map(fn (array $s) => $s['libelle'], Categorie::SECTEURS)),
                'help' => 'Les éducateurs ayant le droit de ce secteur voient et gèrent les joueurs de cette catégorie.',
            ])
            ->add('dureeMatch', IntegerType::class, [
                'label' => 'Durée d\'un match (minutes)',
                'required' => false,
                'attr' => ['min' => 10, 'max' => 120, 'placeholder' => 'ex. : 60', 'class' => 'court'],
                'help' => 'Les deux mi-temps, sans les arrêts de jeu : U11 50, U13 60, U15 80, au-delà 90. '
                    . 'Sert aux minutes des buts et au score par mi-temps des rapports de match. Laissé vide : proposée d\'après le nom.',
            ])
            ->add('feminine', CheckboxType::class, [
                'label' => 'Équipe féminine',
                'required' => false,
                'help' => 'Cochée d\'office pour un nom comme « Féminines », « U15 F » ou « U13 Filles ».',
            ])
            ->add('plateaux', CheckboxType::class, [
                'label' => 'Joue des plateaux (pas de matchs à classement)',
                'required' => false,
                'help' => 'Rassemblements de plusieurs clubs avec de petits matchs, sans classement : le site propose alors des plateaux au lieu de matchs. '
                    . 'Cochée d\'office pour l\'école de foot (U7, U9, U11) ; à cocher pour les U13 si votre district fait de même.',
            ]);

        // Durée laissée vide : celle d'usage pour ce nom de catégorie. Nouvelle catégorie au nom féminin : cochée.
        $builder->addEventListener(FormEvents::POST_SUBMIT, function (FormEvent $event): void {
            $categorie = $event->getData();
            if ($categorie instanceof Categorie && null === $categorie->getDureeMatch()) {
                $categorie->setDureeMatch(Categorie::dureeParDefaut($categorie->getNom()));
            }
            if ($categorie instanceof Categorie && null === $categorie->getId() && Categorie::nomFeminin($categorie->getNom())) {
                $categorie->setFeminine(true);
            }
        });
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => Categorie::class]);
    }
}
