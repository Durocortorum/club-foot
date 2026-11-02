<?php

namespace App\Form;

use App\Entity\JoueurObserve;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\FormType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class JoueurObserveType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('nom')
            ->add('prenom', null, ['label' => 'Prénom', 'required' => false])
            ->add('age', IntegerType::class, ['label' => 'Âge', 'required' => false, 'attr' => ['min' => 5, 'max' => 40]])
            ->add('poste', ListeOuverteType::class, ['suggestions' => JoueurObserve::POSTES])
            ->add('clubActuel', null, ['label' => 'Club actuel', 'required' => false])
            ->add('niveau', ChoiceType::class, ['choices' => array_combine(JoueurObserve::NIVEAUX, JoueurObserve::NIVEAUX), 'required' => false, 'placeholder' => '—'])
            ->add('dateObservation', DateType::class, ['label' => 'Observé le', 'widget' => 'single_text', 'input' => 'datetime_immutable', 'required' => false])
            ->add('commentaire', TextareaType::class, ['label' => 'Commentaire général', 'required' => false])
            ->add('evaluation', FormType::class, ['label' => false, 'required' => false]);

        // Un champ par critère (toutes grilles confondues) ; le gabarit les regroupe par poste.
        foreach (JoueurObserve::GRILLES as $grille) {
            foreach ($grille['criteres'] as $cle => $libelle) {
                if (!$builder->get('evaluation')->has($cle)) {
                    $builder->get('evaluation')->add($cle, TextareaType::class, ['label' => $libelle, 'required' => false, 'attr' => ['rows' => 2]]);
                }
            }
        }
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => JoueurObserve::class]);
    }
}
