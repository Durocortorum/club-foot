<?php

namespace App\Form;

use App\Entity\Blessure;
use App\Entity\Categorie;
use App\Entity\Joueur;
use Doctrine\ORM\EntityRepository;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class BlessureType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $date = ['widget' => 'single_text', 'input' => 'datetime_immutable', 'required' => false];

        // Ordre de saisie : 1. date, catégorie, joueur ; 2. localisation, zone, membre ;
        // 3. type, circonstances, arrêt, reprise, équipe ; 4. commentaire (voir infirmerie/blessure.html.twig).
        $builder
            ->add('date', DateType::class, ['label' => 'Date de la blessure'] + $date)
            // Catégorie : simple filtre de la liste des joueurs (blessure_controller.js), non enregistrée.
            ->add('categorie', EntityType::class, [
                'class' => Categorie::class,
                'mapped' => false,
                'required' => false,
                'label' => 'Catégorie',
                'placeholder' => 'Toutes',
                'data' => ($options['data'] ?? null)?->getJoueur()?->getCategorie(),
                'query_builder' => function (EntityRepository $r) use ($options) {
                    $qb = $r->createQueryBuilder('c')->orderBy('c.ordre')->addOrderBy('c.nom');
                    if (null !== $options['secteurs']) {
                        $qb->andWhere('c.secteur IN (:secteurs)')->setParameter('secteurs', $options['secteurs'] ?: ['-']);
                    }

                    return $qb;
                },
                'disabled' => $options['joueur_fixe'],
            ])
            ->add('joueur', EntityType::class, [
                'class' => Joueur::class,
                'placeholder' => '— Choisir —',
                'choice_label' => fn (Joueur $j) => sprintf('%s %s (%s)', $j->getNom(), $j->getPrenom(), $j->getCategorie() ?? '?'),
                'choice_attr' => fn (Joueur $j) => ['data-categorie' => $j->getCategorie()?->getId(), 'data-equipe' => $j->getCategorie()?->getNom()],
                // Seulement les joueurs du périmètre médical de l'utilisateur (option « secteurs », null : tout le club).
                'query_builder' => function (EntityRepository $r) use ($options) {
                    $qb = $r->createQueryBuilder('j')->leftJoin('j.categorie', 'c')
                        ->where('j.ancien = false')->orderBy('j.nom')->addOrderBy('j.prenom');
                    if (null !== $options['secteurs']) {
                        $qb->andWhere('c.secteur IN (:secteurs)')->setParameter('secteurs', $options['secteurs'] ?: ['-']);
                    }

                    return $qb;
                },
                'disabled' => $options['joueur_fixe'],
            ])
            ->add('localisation', null, ['required' => false, 'attr' => ['placeholder' => 'ex. : Genou gauche']])
            ->add('zone', null, ['label' => 'Zone touchée', 'required' => false, 'attr' => ['placeholder' => 'ex. : Ligament croisé antérieur']])
            ->add('membre', ChoiceType::class, ['choices' => array_combine(Blessure::MEMBRES, Blessure::MEMBRES), 'required' => false, 'placeholder' => '—'])
            ->add('type', ListeOuverteType::class, ['label' => 'Type de blessure', 'suggestions' => Blessure::TYPES])
            ->add('lieu', ListeOuverteType::class, ['label' => 'Circonstances', 'suggestions' => Blessure::LIEUX])
            ->add('avecArret', ChoiceType::class, ['label' => 'Arrêt', 'choices' => ['Avec arrêt' => true, 'Sans arrêt' => false], 'required' => false, 'placeholder' => '—'])
            ->add('arretPrevu', IntegerType::class, ['label' => 'Arrêt envisagé', 'required' => false, 'attr' => ['min' => 1, 'max' => 365, 'placeholder' => 'ex. : 6']])
            ->add('uniteArret', ChoiceType::class, ['label' => 'Unité de l\'arrêt envisagé', 'choices' => ['jours' => 'jours', 'semaines' => 'semaines', 'mois' => 'mois'], 'required' => false, 'placeholder' => false])
            ->add('dateReprise', DateType::class, ['label' => 'Date de reprise', 'help' => 'Laisser vide tant que le joueur est indisponible.'] + $date)
            ->add('equipe', null, ['label' => 'Équipe au moment de la blessure', 'required' => false])
            ->add('commentaire', TextareaType::class, ['required' => false]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => Blessure::class, 'joueur_fixe' => false, 'secteurs' => null]);
        $resolver->setAllowedTypes('secteurs', ['null', 'array']);
    }
}
