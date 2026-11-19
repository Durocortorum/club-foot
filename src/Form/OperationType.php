<?php

namespace App\Form;

use App\Entity\Cotisation;
use App\Entity\Operation;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\MoneyType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\File;

/**
 * Recette ou dépense. Entrées d'un match : nombre d'entrées et prix de la place, le montant est calculé.
 */
class OperationType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $recette = Operation::RECETTE === $options['data']?->getSens();
        $builder
            ->add('date', DateType::class, ['widget' => 'single_text', 'input' => 'datetime_immutable'])
            ->add('rubrique', ListeOuverteType::class, ['suggestions' => $recette ? Operation::RUBRIQUES_RECETTES : Operation::RUBRIQUES_DEPENSES])
            ->add('libelle', null, ['label' => 'Libellé', 'attr' => ['placeholder' => $recette ? 'ex. : Match U19 contre Troyes' : 'ex. : 20 ballons taille 5']])
            ->add('montant', MoneyType::class, ['currency' => 'EUR', 'required' => false, 'scale' => 2, 'attr' => ['placeholder' => '0,00']]);
        if ($recette) {
            $builder
                ->add('nbEntrees', IntegerType::class, ['label' => 'Nombre d\'entrées payantes', 'required' => false, 'attr' => ['min' => 0]])
                ->add('prixPlace', MoneyType::class, ['label' => 'Prix de la place', 'currency' => 'EUR', 'required' => false, 'scale' => 2]);
        }
        $builder
            ->add('mode', ChoiceType::class, ['label' => 'Mode de paiement', 'choices' => array_combine(Cotisation::MODES, Cotisation::MODES), 'required' => false, 'placeholder' => '—'])
            ->add('tiers', null, ['label' => $recette ? 'Versé par' : 'Fournisseur / payé à', 'required' => false])
            ->add('note', null, ['required' => false])
            ->add('justificatif', FileType::class, [
                'label' => 'Justificatif (facture, ticket)',
                'mapped' => false,
                'required' => false,
                'help' => $options['data']?->getFichier() ? 'Un justificatif est déjà joint : en choisir un autre le remplace.' : 'PDF ou photo.',
                'constraints' => [new File(maxSize: '20M', mimeTypes: ['application/pdf', 'image/jpeg', 'image/png'], mimeTypesMessage: 'Le justificatif doit être un PDF ou une photo (JPEG, PNG).')],
            ])
            // Avant la validation : montant des entrées = nombre × prix de la place.
            ->addEventListener(FormEvents::POST_SUBMIT, function (FormEvent $e) {
                $e->getData()?->calculerMontant();
            }, 10);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => Operation::class]);
    }
}
