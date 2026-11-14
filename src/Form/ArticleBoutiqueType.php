<?php

namespace App\Form;

use App\Entity\ArticleBoutique;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\MoneyType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\LessThan;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\Positive;

/** Article de la boutique ; le stock par taille est saisi à part (voir BoutiqueController). */
class ArticleBoutiqueType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('nom', null, ['attr' => ['maxlength' => 120, 'placeholder' => 'ex. : Survêtement du club']])
            ->add('prix', MoneyType::class, ['currency' => 'EUR', 'html5' => true, 'attr' => ['min' => 0, 'step' => '0.01'],
                'constraints' => [new NotBlank(message: 'Indiquez le prix.'), new Positive(message: 'Le prix doit être positif.'), new LessThan(value: 10000, message: 'Prix trop élevé.')]])
            ->add('taillesTexte', TextType::class, ['label' => 'Tailles', 'required' => false, 'attr' => ['placeholder' => 'ex. : 8 ans, 10 ans, 12 ans, S, M, L, XL'],
                'help' => 'Séparées par des virgules. Vide : taille unique.'])
            ->add('description', TextareaType::class, ['required' => false, 'attr' => ['rows' => 3, 'placeholder' => 'Matière, coupe, flocage possible…']])
            ->add('photo', PhotoType::class, ['label' => 'Photo'])
            ->add('enVente', CheckboxType::class, ['label' => 'En vente (visible des familles)', 'required' => false])
            ->add('suiviStock', CheckboxType::class, ['label' => 'Suivre le stock par taille', 'mapped' => false, 'required' => false,
                'data' => $options['data']?->isSuiviStock() ?? false,
                'help' => 'Coché : les familles ne peuvent pas commander plus que le stock ; il diminue à chaque commande et revient si elle est annulée.']);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => ArticleBoutique::class]);
    }
}
