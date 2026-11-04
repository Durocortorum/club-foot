<?php

namespace App\Form;

use App\Entity\Categorie;
use App\Entity\Educateur;
use App\Entity\Joueur;
use Doctrine\ORM\EntityRepository;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\TelType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class JoueurType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $date = ['widget' => 'single_text', 'input' => 'datetime_immutable', 'required' => false];
        $choix = fn (array $valeurs) => array_combine($valeurs, $valeurs);

        $builder
            // Identité
            ->add('nom')
            ->add('prenom', null, ['label' => 'Prénom'])
            ->add('surnom', null, ['required' => false])
            ->add('dateNaissance', DateType::class, ['label' => 'Date de naissance', 'attr' => ['data-mineur-target' => 'naissance', 'data-action' => 'mineur#basculer']] + $date)
            ->add('lieuNaissance', null, ['label' => 'Lieu de naissance', 'required' => false])
            ->add('nationalite', null, ['label' => 'Nationalité', 'required' => false])
            ->add('photo', PhotoType::class)
            // Club
            ->add('categorie', EntityType::class, [
                'class' => Categorie::class,
                'label' => 'Catégorie',
                'placeholder' => '— Choisir —',
                'query_builder' => fn (EntityRepository $r) => $r->createQueryBuilder('c')
                    ->where('c.secteur IN (:s)')->setParameter('s', $options['secteurs'])
                    ->orderBy('c.ordre'),
                'group_by' => fn (Categorie $c) => $c->getSecteurLibelle(),
            ])
            ->add('educateur', EntityType::class, [
                'class' => Educateur::class,
                'label' => 'Éducateur',
                'required' => false,
                'placeholder' => '— Aucun —',
                'query_builder' => fn (EntityRepository $r) => $r->createQueryBuilder('e')->orderBy('e.nom')->addOrderBy('e.prenom'),
            ])
            ->add('licence', null, ['label' => 'N° de licence', 'required' => false])
            ->add('poste', ChoiceType::class, ['choices' => $choix(Joueur::POSTES), 'required' => false, 'placeholder' => '—'])
            ->add('pied', ChoiceType::class, ['label' => 'Pied fort', 'choices' => $choix(Joueur::PIEDS), 'required' => false, 'placeholder' => '—'])
            ->add('dateArrivee', DateType::class, ['label' => "Date d'arrivée au club"] + $date)
            ->add('selectionne', CheckboxType::class, ['label' => 'Sélectionné', 'required' => false])
            ->add('ancien', CheckboxType::class, ['label' => 'Ancien joueur (a quitté le club)', 'required' => false])
            ->add('memo', TextareaType::class, ['label' => 'Mémo', 'required' => false])
            // Coordonnées
            ->add('adresse', null, ['required' => false])
            ->add('codePostal', null, ['label' => 'Code postal', 'required' => false])
            ->add('ville', null, ['required' => false])
            ->add('telJoueur', TelType::class, ['label' => 'Portable du joueur', 'required' => false])
            ->add('telMaisonMere', TelType::class, ['label' => 'Tél. maison (mère)', 'required' => false])
            ->add('telMaisonPere', TelType::class, ['label' => 'Tél. maison (père)', 'required' => false])
            ->add('telPortable1', TelType::class, ['label' => 'Portable parent 1', 'required' => false])
            ->add('telPortable2', TelType::class, ['label' => 'Portable parent 2', 'required' => false])
            ->add('emailJoueur', EmailType::class, ['label' => 'E-mail du joueur', 'required' => false])
            ->add('emailParents', EmailType::class, ['label' => 'E-mail des parents', 'required' => false])
            // Famille
            ->add('nomPere', null, ['label' => 'Nom du père', 'required' => false])
            ->add('professionPere', null, ['label' => 'Profession du père', 'required' => false])
            ->add('nomMere', null, ['label' => 'Nom de la mère', 'required' => false])
            ->add('professionMere', null, ['label' => 'Profession de la mère', 'required' => false])
            ->add('droitImage', CheckboxType::class, ["label" => "Droit à l'image", 'required' => false])
            ->add('transportEducateur', CheckboxType::class, ['label' => 'Transport par un éducateur autorisé', 'required' => false])
            ->add('transportParent', CheckboxType::class, ['label' => 'Transport par un parent autorisé', 'required' => false])
            // Scolarité
            ->add('niveauScolaire', null, ['label' => 'Classe', 'required' => false])
            ->add('ecole', null, ['label' => 'École', 'required' => false])
            ->add('etablissement', null, ['label' => 'Établissement', 'required' => false])
            ->add('situationScolaire', null, ['label' => 'Régime', 'required' => false, 'attr' => ['placeholder' => 'Externe, interne CREPS…']]);

        if ($options['avec_medical']) {
            $builder
                ->add('nomAssure', null, ['label' => "Nom de l'assuré", 'required' => false])
                ->add('numeroSecu', null, ['label' => 'N° de sécurité sociale', 'required' => false])
                ->add('medecin', null, ['label' => 'Médecin traitant', 'required' => false])
                ->add('telMedecin', TelType::class, ['label' => 'Tél. du médecin', 'required' => false])
                ->add('renseignementsMedicaux', TextareaType::class, ['label' => 'Renseignements médicaux', 'required' => false]);
        }
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Joueur::class,
            'secteurs' => [],
            'avec_medical' => false,
        ]);
        $resolver->setAllowedTypes('secteurs', 'array');
        $resolver->setAllowedTypes('avec_medical', 'bool');
    }
}
