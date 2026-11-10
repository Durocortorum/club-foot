<?php

namespace App\Form;

use App\Entity\Candidature;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\DateTimeType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\File;

class CandidatureType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $date = ['widget' => 'single_text', 'input' => 'datetime_immutable', 'required' => false];
        $builder
            ->add('nom')
            ->add('prenom', null, ['label' => 'Prénom'])
            ->add('posteVise', ChoiceType::class, ['label' => 'Poste visé', 'choices' => array_flip(Candidature::POSTES)])
            ->add('fonction', ListeOuverteType::class, [
                'label' => 'Fonction',
                'suggestions' => array_merge(...array_values(Candidature::FONCTIONS)),
                'attr' => ['placeholder' => 'Éducateur U13, kinésithérapeute…'],
            ])
            ->add('dateNaissance', DateType::class, ['label' => 'Date de naissance'] + $date)
            ->add('telephone', null, ['label' => 'Téléphone', 'required' => false])
            ->add('email', EmailType::class, ['label' => 'E-mail', 'required' => false])
            ->add('adresse', null, ['required' => false])
            ->add('codePostal', null, ['label' => 'Code postal', 'required' => false])
            ->add('ville', null, ['required' => false])
            ->add('diplomes', null, ['label' => 'Diplômes', 'required' => false, 'attr' => ['placeholder' => 'CFF, BMF, BEF, diplôme d\'État…']])
            ->add('clubActuel', null, ['label' => 'Club actuel', 'required' => false])
            ->add('experience', TextareaType::class, ['label' => 'Parcours, expérience', 'required' => false])
            ->add('disponibilites', null, ['label' => 'Disponibilités', 'required' => false, 'attr' => ['placeholder' => 'Mercredi après-midi, week-end…']])
            ->add('origine', ListeOuverteType::class, [
                'label' => 'Origine de la candidature',
                'suggestions' => ['Candidature spontanée', 'Recommandation', 'Parent de joueur', 'Ancien joueur', 'Réseaux sociaux', 'Annonce du club', 'District / Ligue'],
            ])
            ->add('dateReception', DateType::class, ['label' => 'Reçue le'] + $date)
            ->add('statut', ChoiceType::class, ['choices' => array_flip(Candidature::STATUTS)])
            ->add('dateEntretien', DateTimeType::class, ['label' => 'Entretien le'] + $date)
            ->add('appreciation', TextareaType::class, ['label' => 'Appréciation après entretien', 'required' => false])
            ->add('document', FileType::class, [
                'label' => 'CV (PDF)',
                'mapped' => false,
                'required' => false,
                'attr' => ['accept' => 'application/pdf'],
                'constraints' => [new File(maxSize: '10M', mimeTypes: ['application/pdf'], mimeTypesMessage: 'Le CV doit être un fichier PDF.')],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => Candidature::class]);
    }
}
