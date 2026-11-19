<?php

namespace App\Form;

use App\Entity\Joueur;
use App\Entity\Participation;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class ParticipationType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('numero', IntegerType::class, ['label' => 'N°', 'required' => false, 'attr' => ['min' => 1, 'max' => 99, 'class' => 'court', 'data-feuille-numero' => '']])
            // Sur le terrain au coup d'envoi : cochée d'office pour les n° 1 à 11 (contrôleur « feuille »), modifiable.
            // Après le numéro : le numéro coche d'abord la case, puis la case envoyée l'emporte.
            ->add('titulaire', CheckboxType::class, ['label' => 'Titulaire', 'required' => false, 'attr' => [
                'data-feuille-titulaire' => '', 'title' => 'Titulaire : sur le terrain au coup d\'envoi',
            ]])
            ->add('joueur', EntityType::class, [
                'class' => Joueur::class,
                'choices' => $options['joueurs'],
                'choice_label' => fn (Joueur $j) => $j->getNom() . ' ' . $j->getPrenom(),
                'placeholder' => '— Joueur —',
                'attr' => ['data-feuille-target' => 'convoque'],
                // Catégorie du joueur : filtre de la feuille et mention « (U15) » s'il est surclassé (contrôleur « feuille »).
                // « data-convoque » : convoqué (1) ou non (0) quand le match a une convocation : seuls les convoqués sont proposés.
                'choice_attr' => fn (Joueur $j) => ['data-categorie' => $j->getCategorie()?->getId(), 'data-categorie-nom' => (string) $j->getCategorie()]
                    + (null === $options['convoques'] ? [] : ['data-convoque' => \in_array($j->getId(), $options['convoques'], true) ? '1' : '0']),
            ])
            ->add('poste', ListeOuverteType::class, ['suggestions' => Participation::POSTES, 'attr' => ['data-feuille-poste' => '']])
            // Calculé dans la page à partir des titulaires et des remplacements (contrôleur « feuille ») ;
            // le plus long sert aussi de durée de match à défaut de celle de la catégorie (contrôleur « score »).
            ->add('tempsJeu', IntegerType::class, ['label' => 'Min.', 'required' => false, 'attr' => [
                'min' => 0, 'max' => 130, 'class' => 'court calcule', 'readonly' => true, 'tabindex' => -1,
                'title' => 'Calculé à partir des remplacements', 'data-score-target' => 'temps', 'data-feuille-temps' => '',
            ]])
            ->add('carton', ChoiceType::class, [
                'choices' => array_flip(Participation::CARTONS),
                'required' => false,
                'placeholder' => '—',
                // Rouge : le temps de jeu s'arrête à la minute du carton ; « BL » (exclusion temporaire) :
                // 10 minutes en moins (contrôleur « feuille »). Cartons affichés sous le score (contrôleur « score »).
                'choice_attr' => fn (string $code) => ['data-couleur' => Participation::couleurDe($code)],
                'attr' => ['data-feuille-carton' => ''],
            ])
            ->add('appreciation', TextareaType::class, ['label' => 'Appréciation', 'required' => false, 'attr' => ['rows' => 1]]);
        ChampNote::poser($builder, 'note', 'Note', fn (Participation $p) => $p->getNote(), ['class' => 'note-liste']);
        // Minute du carton, affichée seulement quand un carton est choisi (contrôleur « score »).
        ChampMinute::poser($builder, 'minuteCarton', 'Min. carton', fn (Participation $p) => $p->getMinuteCarton(), $options['duree'],
            ['class' => 'minute-but', 'title' => 'Minute du carton', 'data-score-target' => 'minuteCarton', 'data-feuille-minute-carton' => '']);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        // duree : durée de match de la catégorie du match (minutes proposées pour les cartons, voir RencontreType).
        $resolver->setDefaults(['data_class' => Participation::class, 'joueurs' => [], 'duree' => 90, 'convoques' => null]);
        $resolver->setAllowedTypes('duree', 'int');
    }
}
