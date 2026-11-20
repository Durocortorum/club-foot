<?php

namespace App\Form;

use App\Entity\Categorie;
use App\Entity\Rencontre;
use App\Repository\CategorieRepository;
use Doctrine\ORM\EntityRepository;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\CollectionType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\NotBlank;

/**
 * Rapport de match complet : en-tête, feuille de match, buts et remplacements.
 */
class RencontreType extends AbstractType
{
    public function __construct(private readonly CategorieRepository $categories)
    {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $choix = fn (array $valeurs) => array_combine($valeurs, $valeurs);
        $toutes = $this->categories->findAll();
        // Score : champs cachés remplis à partir des buts par le contrôleur Stimulus « score ».
        $score = fn (string $cible) => ['required' => false, 'label' => false, 'attr' => ['min' => 0, 'data-score-target' => $cible]];
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
                'query_builder' => function (EntityRepository $r) use ($options) {
                    $qb = $r->createQueryBuilder('c')->where('c.secteur IN (:s)')->setParameter('s', $options['secteurs']);
                    // Équipes dont ce compte tient les matchs (éducateur responsable ou personne désignée).
                    if (null !== $options['categories']) {
                        $qb->andWhere('c.id IN (:ids)')->setParameter('ids', $options['categories'] ?: [0]);
                    }

                    return $qb->orderBy('c.ordre');
                },
                'group_by' => fn (Categorie $c) => $c->getSecteurLibelle(),
                // Durée de match de la catégorie : minutes des buts et mi-temps (contrôleur « score ») ;
                // catégories dont les joueurs peuvent être surclassés dans celle-ci (contrôleur « feuille »).
                'choice_attr' => fn (Categorie $c) => [
                    'data-duree' => $c->getDureeMatch(),
                    // Joueurs sur le terrain (sans système choisi) : nombre maximum de titulaires (contrôleur « feuille »).
                    'data-joueurs' => Categorie::joueursParDefaut($c->getNom()),
                    'data-surclassement' => implode(',', array_map(fn (Categorie $a) => $a->getId(),
                        array_filter($toutes, fn (Categorie $a) => $c->accueilleEnSurclassement($a)))),
                ],
                'attr' => ['data-feuille-target' => 'categorie', 'data-score-target' => 'categorie', 'data-action' => 'feuille#filtrer score#changerCategorie'],
            ])
            ->add('competition', ChoiceType::class, ['label' => 'Compétition', 'choices' => $choix(Rencontre::COMPETITIONS), 'required' => false, 'placeholder' => '—'])
            ->add('butsDomicileMiTemps', IntegerType::class, $score('mt1Dom'))
            ->add('butsExterieurMiTemps', IntegerType::class, $score('mt1Ext'))
            ->add('butsDomicile', IntegerType::class, $score('finDom'))
            ->add('butsExterieur', IntegerType::class, $score('finExt'))
            ->add('lieu', null, ['label' => 'Stade', 'required' => false])
            ->add('rapport', TextareaType::class, ['label' => 'Rapport de l\'éducateur', 'required' => false, 'attr' => ['rows' => 8]])
            // Joueurs convoqués (hors absents) : seuls proposés sur la feuille, sauf « tous les joueurs » (contrôleur « feuille »).
            ->add('participations', CollectionType::class, array_merge_recursive($collection(ParticipationType::class), ['entry_options' => ['duree' => $options['duree'], 'convoques' => $options['convoques']]]))
            ->add('buts', CollectionType::class, array_merge_recursive($collection(ButType::class), ['entry_options' => ['duree' => $options['duree']]]))
            ->add('remplacements', CollectionType::class, $collection(RemplacementType::class));

        ChampNote::poser($builder, 'note', 'Note du match (sur 10)', fn (Rencontre $r) => $r->getNote());

        // Conditions : listes déroulantes, plus la valeur déjà enregistrée ou envoyée si elle n'y figure pas
        // (anciens matchs saisis librement, ex. : « 442 »).
        $listes = ['terrain' => ['Terrain', Rencontre::TERRAINS], 'meteo' => ['Météo', Rencontre::METEOS],
            'vent' => ['Vent', Rencontre::VENTS], 'temperature' => ['Température (°C)', array_map('strval', range(-15, 40))],
            'systeme' => ['Organisation de jeu', Rencontre::SYSTEMES]];
        $ajouterListes = function (FormInterface $form, callable $valeur) use ($listes): void {
            foreach ($listes as $nom => [$label, $valeurs]) {
                $groupes = array_is_list($valeurs) ? $this->choix($valeurs) : array_map(fn (array $v) => $this->choix($v), $valeurs);
                $actuelle = $valeur($nom);
                $connues = array_is_list($valeurs) ? $valeurs : array_merge(...array_values($valeurs));
                // Organisation : aussi « Placement par l'éducateur » (joueurs placés à la main sur le terrain).
                if ('systeme' === $nom) {
                    $groupes = ['Placement libre' => [Rencontre::LIBELLE_EDUCATEUR => Rencontre::SYSTEME_EDUCATEUR]] + $groupes;
                    $connues[] = Rencontre::SYSTEME_EDUCATEUR;
                }
                if (null !== $actuelle && '' !== $actuelle && !\in_array($actuelle, $connues, true)) {
                    $groupes = array_is_list($valeurs) ? $groupes + [$actuelle => $actuelle] : $groupes + ['Autre' => [$actuelle => $actuelle]];
                }
                $form->add($nom, ChoiceType::class, ['label' => $label, 'choices' => $groupes, 'required' => false, 'placeholder' => '—']);
            }
        };
        $builder->addEventListener(FormEvents::PRE_SET_DATA, function (FormEvent $e) use ($ajouterListes): void {
            $r = $e->getData();
            $ajouterListes($e->getForm(), fn (string $nom) => $r instanceof Rencontre ? $r->{'get' . ucfirst($nom)}() : null);
        });
        $builder->addEventListener(FormEvents::PRE_SUBMIT, function (FormEvent $e) use ($ajouterListes): void {
            $donnees = $e->getData();
            $ajouterListes($e->getForm(), fn (string $nom) => isset($donnees[$nom]) ? (string) $donnees[$nom] : null);
        });

        // Équipes : le club, l'adversaire et le côté du club, plutôt que « domicile » et « extérieur ».
        $builder->addEventListener(FormEvents::PRE_SET_DATA, function (FormEvent $event): void {
            /** @var Rencontre|null $r */
            $r = $event->getData();
            $aDomicile = !$r || $r->isADomicile();
            $nom = fn (string $label, string $cible, ?string $valeur) => [
                'mapped' => false,
                'label' => $label,
                'data' => $valeur,
                'constraints' => [new NotBlank(message: 'Champ obligatoire.')],
                'attr' => ['class' => 'majuscules', 'data-score-target' => $cible, 'maxlength' => 255],
            ];
            $event->getForm()
                // Tant que le match n'est pas joué, il n'a pas de score : il ne compte ni dans le bilan ni comme un nul.
                ->add('joue', CheckboxType::class, [
                    'mapped' => false,
                    'required' => false,
                    'label' => 'Match joué : le score compte dans le bilan et les statistiques',
                    'data' => null !== $r?->getButsDomicile() || null !== $r?->getButsExterieur(),
                ])
                ->add('cote', ChoiceType::class, [
                    'mapped' => false,
                    'label' => 'Le club joue',
                    'choices' => ['À domicile' => 'domicile', 'À l\'extérieur' => 'exterieur'],
                    'expanded' => true,
                    'data' => $aDomicile ? 'domicile' : 'exterieur',
                    'choice_attr' => fn () => ['data-score-target' => 'cote', 'data-action' => 'score#changerCote'],
                ])
                ->add('club', TextType::class, $nom('Club', 'club', $aDomicile ? $r?->getDomicile() : $r?->getExterieur()))
                ->add('adversaire', TextType::class, $nom('Adversaire', 'adversaire', $aDomicile ? $r?->getExterieur() : $r?->getDomicile()));
        });
        // Puis rangés à domicile ou à l'extérieur selon le côté choisi, en majuscules.
        $builder->addEventListener(FormEvents::SUBMIT, function (FormEvent $event): void {
            $form = $event->getForm();
            $r = $event->getData();
            if (!$r instanceof Rencontre) {
                return;
            }
            $club = mb_strtoupper(trim((string) $form->get('club')->getData()));
            $adversaire = mb_strtoupper(trim((string) $form->get('adversaire')->getData()));
            [$domicile, $exterieur] = 'exterieur' === $form->get('cote')->getData() ? [$adversaire, $club] : [$club, $adversaire];
            $r->setDomicile($domicile ?: null)->setExterieur($exterieur ?: null);

            // Pas joué (case décochée et aucun but saisi) : pas de score, même 0 – 0.
            if (!$form->get('joue')->getData() && $r->getButs()->isEmpty()) {
                $r->setButsDomicile(null)->setButsExterieur(null)->setButsDomicileMiTemps(null)->setButsExterieurMiTemps(null);
            }
        });
    }

    /** @param list<string> $valeurs @return array<string, string> libellé => valeur */
    private function choix(array $valeurs): array
    {
        return array_combine($valeurs, $valeurs);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Rencontre::class,
            'secteurs' => [],
            'joueurs' => [],
            // Ids des joueurs convoqués (hors absents) ; null : pas de convocation, tous les joueurs proposés.
            'convoques' => null,
            // Durée de match de la catégorie (minutes proposées pour les buts).
            'duree' => 90,
            // Ids des équipes proposées (celles dont le compte tient les matchs) ; null : toutes celles des secteurs.
            'categories' => null,
        ]);
        $resolver->setAllowedTypes('duree', 'int');
        $resolver->setAllowedTypes('categories', ['null', 'array']);
        $resolver->setAllowedTypes('secteurs', 'array');
        $resolver->setAllowedTypes('joueurs', 'array');
        $resolver->setAllowedTypes('convoques', ['null', 'array']);
    }
}
