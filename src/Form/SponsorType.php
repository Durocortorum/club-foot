<?php

namespace App\Form;

use App\Entity\Sponsor;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\TelType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/** Fiche sponsor : qui, contact, coordonnées. Les participations se saisissent à part, saison par saison. */
class SponsorType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('nom', null, ['label' => 'Nom de l\'entreprise ou du particulier', 'attr' => ['class' => 'majuscules']])
            ->add('type', ChoiceType::class, ['label' => 'Type', 'choices' => array_combine(Sponsor::TYPES, Sponsor::TYPES)])
            ->add('contact', null, ['label' => 'Personne à contacter', 'required' => false])
            ->add('telephone', TelType::class, ['label' => 'Téléphone', 'required' => false])
            ->add('email', EmailType::class, ['label' => 'E-mail', 'required' => false])
            ->add('adresse', null, ['label' => 'Adresse', 'required' => false])
            ->add('codePostal', null, ['label' => 'Code postal', 'required' => false])
            ->add('ville', null, ['label' => 'Ville', 'required' => false])
            ->add('siteWeb', null, ['label' => 'Site internet', 'required' => false])
            ->add('ancien', CheckboxType::class, ['label' => 'N\'est plus partenaire du club', 'required' => false])
            ->add('memo', TextareaType::class, ['label' => 'Mémo', 'required' => false, 'attr' => ['rows' => 4]]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => Sponsor::class]);
    }
}
