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
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Plateau de l'école de foot : quand, où, chez qui, avec quels clubs, petits matchs (sans classement) et bilan.
 */
class PlateauType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('date', DateType::class, ['widget' => 'single_text', 'input' => 'datetime_immutable'])
            ->add('heure', null, ['required' => false, 'attr' => ['placeholder' => '10H00']])
            ->add('categorie', EntityType::class, [
                'class' => Categorie::class,
                'label' => 'Équipe',
                'placeholder' => '— Choisir —',
                // Équipes à plateaux dont ce compte tient les matchs.
                'query_builder' => fn (EntityRepository $r) => $r->createQueryBuilder('c')
                    ->where('c.id IN (:ids)')->setParameter('ids', $options['categories'] ?: [0])->orderBy('c.ordre'),
            ])
            ->add('lieu', null, ['label' => 'Lieu (stade, adresse)', 'required' => false])
            ->add('matchsPlateau', CollectionType::class, [
                'entry_type' => PetitMatchType::class,
                'entry_options' => ['label' => false],
                'allow_add' => true,
                'allow_delete' => true,
                'label' => false,
            ])
            ->add('rapport', TextareaType::class, ['label' => 'Bilan de l\'éducateur', 'required' => false, 'attr' => ['rows' => 5, 'placeholder' => 'Comment s\'est passé le plateau, progrès, points à travailler…']]);

        // Chez nous ou chez un autre club (rangé dans « domicile »), et les autres clubs présents.
        $builder->addEventListener(FormEvents::PRE_SET_DATA, function (FormEvent $event): void {
            /** @var Rencontre|null $r */
            $r = $event->getData();
            $chezNous = !$r?->getId() || $r->isADomicile();
            $event->getForm()
                ->add('chez', ChoiceType::class, [
                    'mapped' => false,
                    'label' => 'Le plateau a lieu',
                    'choices' => ['Chez nous' => 'nous', 'Chez un autre club' => 'autre'],
                    'expanded' => true,
                    'data' => $chezNous ? 'nous' : 'autre',
                ])
                ->add('organisateur', TextType::class, [
                    'mapped' => false,
                    'required' => false,
                    'label' => 'Club qui organise',
                    'data' => $chezNous ? null : $r?->getOrganisateur(),
                    'attr' => ['class' => 'majuscules', 'maxlength' => 80, 'placeholder' => 'ex. : US VALLÉE VERTE'],
                ])
                ->add('clubs', TextType::class, [
                    'mapped' => false,
                    'required' => false,
                    'label' => 'Autres clubs présents',
                    'data' => implode(', ', $r?->getClubsPlateau() ?? []),
                    'help' => 'Séparés par des virgules. Facultatif.',
                    'attr' => ['maxlength' => 500, 'placeholder' => 'ex. : FC BORDS DE LOIRE, SC LES MOULINS'],
                ]);
        });
        $builder->addEventListener(FormEvents::SUBMIT, function (FormEvent $event) use ($options): void {
            $form = $event->getForm();
            $r = $event->getData();
            if (!$r instanceof Rencontre) {
                return;
            }
            $club = mb_strtoupper($options['club']);
            $organisateur = mb_strtoupper(trim((string) $form->get('organisateur')->getData()));
            if ('autre' === $form->get('chez')->getData() && '' === $organisateur) {
                $form->get('organisateur')->addError(new FormError('Indiquez le club qui organise le plateau.'));
            }
            $r->setCompetition(Rencontre::PLATEAU)
                ->setDomicile('autre' === $form->get('chez')->getData() && '' !== $organisateur ? $organisateur : $club)
                ->setExterieur($club)
                ->setClubsPlateau(preg_split('/[,;\n]+/', (string) $form->get('clubs')->getData()) ?: [])
                // Pas de score d'ensemble : un plateau ne compte ni dans le bilan, ni dans un classement.
                ->setButsDomicile(null)->setButsExterieur(null)->setButsDomicileMiTemps(null)->setButsExterieurMiTemps(null);
        });
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Rencontre::class,
            // Ids des équipes à plateaux proposées (celles dont le compte tient les matchs).
            'categories' => [],
            // Nom du club (club organisateur d'un plateau « chez nous »).
            'club' => '',
        ]);
        $resolver->setAllowedTypes('categories', 'array');
        $resolver->setAllowedTypes('club', 'string');
    }
}
