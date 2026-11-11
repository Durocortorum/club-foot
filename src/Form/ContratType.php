<?php

namespace App\Form;

use App\Entity\Contrat;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\Extension\Core\Type\MoneyType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\File;

class ContratType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $date = ['widget' => 'single_text', 'input' => 'datetime_immutable', 'required' => false];
        $builder
            ->add('objet', null, ['attr' => ['placeholder' => 'ex. : Location du terrain synthétique']])
            ->add('type', ListeOuverteType::class, ['suggestions' => Contrat::TYPES])
            ->add('partie', null, ['label' => 'Avec qui', 'attr' => ['placeholder' => 'Personne, entreprise, mairie…']])
            ->add('debut', DateType::class, ['label' => 'Début'] + $date)
            ->add('fin', DateType::class, ['label' => 'Fin', 'help' => 'Le contrat est signalé ' . Contrat::ALERTE_JOURS . ' jours avant.'] + $date)
            ->add('montant', MoneyType::class, ['currency' => 'EUR', 'required' => false, 'scale' => 2])
            ->add('periodicite', ChoiceType::class, ['label' => 'Montant payé', 'choices' => array_combine(Contrat::PERIODICITES, Contrat::PERIODICITES), 'required' => false, 'placeholder' => '—'])
            ->add('document', FileType::class, [
                'label' => 'Contrat signé (PDF)',
                'mapped' => false,
                'required' => false,
                'help' => $options['data']?->getFichier() ? 'Un PDF est déjà joint : en choisir un autre le remplace.' : null,
                'constraints' => [new File(maxSize: '20M', mimeTypes: ['application/pdf'], mimeTypesMessage: 'Le contrat doit être un fichier PDF.')],
            ])
            ->add('note', TextareaType::class, ['required' => false, 'attr' => ['placeholder' => 'Conditions, renouvellement, préavis…']]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => Contrat::class]);
    }
}
