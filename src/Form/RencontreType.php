<?php

namespace App\Form;

use App\Entity\Categorie;
use App\Entity\Rencontre;
use Doctrine\ORM\EntityRepository;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\CollectionType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Rapport de match complet : en-tête, feuille de match, buts et remplacements.
 */
class RencontreType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $choix = fn (array $valeurs) => array_combine($valeurs, $valeurs);
        $score = ['required' => false, 'attr' => ['min' => 0, 'class' => 'court']];
        $collection = fn (string $type) => [
            'entry_type' => $type,
            'entry_options' => ['joueurs' => $options['joueurs'], 'label' => false],
            'allow_add' => true,
            'allow_delete' => true,
            'by_reference' => false,
            'label' => false,
        ];

        $builder
            ->add('date', DateType::class, ['widget' => 'single_text', 'input' => 'datetime_immutable'])
            ->add('heure', null, ['required' => false, 'attr' => ['placeholder' => '14H30']])
            ->add('categorie', EntityType::class, [
                'class' => Categorie::class,
                'label' => 'Catégorie',
                'placeholder' => '— Choisir —',
                'query_builder' => fn (EntityRepository $r) => $r->createQueryBuilder('c')
                    ->where('c.secteur IN (:s)')->setParameter('s', $options['secteurs'])
                    ->orderBy('c.ordre'),
                'group_by' => fn (Categorie $c) => $c->getSecteurLibelle(),
                'attr' => ['data-feuille-target' => 'categorie', 'data-action' => 'feuille#filtrer'],
            ])
            ->add('equipe', null, ['label' => 'Équipe (précision)', 'required' => false, 'attr' => ['placeholder' => 'ex. : U13 Excellence']])
            ->add('competition', ChoiceType::class, ['label' => 'Compétition', 'choices' => $choix(Rencontre::COMPETITIONS), 'required' => false, 'placeholder' => '—'])
            ->add('domicile', null, ['label' => 'Équipe à domicile'])
            ->add('exterieur', null, ['label' => 'Équipe à l\'extérieur'])
            ->add('butsDomicile', IntegerType::class, ['label' => 'Buts domicile'] + $score)
            ->add('butsExterieur', IntegerType::class, ['label' => 'Buts extérieur'] + $score)
            ->add('butsDomicileMiTemps', IntegerType::class, ['label' => 'Mi-temps domicile'] + $score)
            ->add('butsExterieurMiTemps', IntegerType::class, ['label' => 'Mi-temps extérieur'] + $score)
            ->add('lieu', null, ['label' => 'Stade', 'required' => false])
            ->add('terrain', ListeOuverteType::class, ['suggestions' => Rencontre::TERRAINS])
            ->add('meteo', null, ['label' => 'Météo', 'required' => false, 'attr' => ['placeholder' => 'Couvert, ensoleillé…']])
            ->add('vent', null, ['required' => false])
            ->add('temperature', null, ['label' => 'Température (°C)', 'required' => false])
            ->add('systeme', null, ['label' => 'Organisation de jeu', 'required' => false, 'attr' => ['placeholder' => '4 4 2']])
            ->add('note', NumberType::class, ['label' => 'Note du match (sur 10)', 'required' => false, 'scale' => 1, 'html5' => true, 'attr' => ['step' => 0.5, 'min' => 0, 'max' => 10]])
            ->add('rapport', TextareaType::class, ['label' => 'Rapport de l\'éducateur', 'required' => false, 'attr' => ['rows' => 8]])
            ->add('participations', CollectionType::class, $collection(ParticipationType::class))
            ->add('buts', CollectionType::class, $collection(ButType::class))
            ->add('remplacements', CollectionType::class, $collection(RemplacementType::class));
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Rencontre::class,
            'secteurs' => [],
            'joueurs' => [],
        ]);
        $resolver->setAllowedTypes('secteurs', 'array');
        $resolver->setAllowedTypes('joueurs', 'array');
    }
}
