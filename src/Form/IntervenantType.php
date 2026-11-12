<?php

namespace App\Form;

use App\Entity\Arbitre;
use App\Entity\Categorie;
use App\Entity\Dirigeant;
use App\Entity\Educateur;
use App\Entity\Intervenant;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Doctrine\ORM\EntityRepository;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\TelType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Fiche éducateur ou dirigeant (même structure dans Access).
 */
class IntervenantType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('nom')
            ->add('prenom', null, ['label' => 'Prénom'])
            ->add('dateNaissance', DateType::class, ['label' => 'Date de naissance', 'widget' => 'single_text', 'input' => 'datetime_immutable', 'required' => false])
            ->add('photo', PhotoType::class)
            ->add('fonction', null, ['label' => 'Équipe ou fonction', 'required' => false])
            ->add('fonction2', null, ['label' => 'Seconde équipe ou fonction', 'required' => false])
            ->add('diplome', null, ['label' => 'Diplôme', 'required' => false])
            ->add('dateDiplome', DateType::class, ['label' => 'Diplôme obtenu le', 'widget' => 'single_text', 'input' => 'datetime_immutable', 'required' => false])
            ->add('permis', null, ['label' => 'Permis de conduire', 'required' => false, 'attr' => ['maxlength' => 30, 'placeholder' => 'ex. : B, ou B et D1'], 'help' => 'Catégories du permis ; vide si pas de permis. Le contrôle du permis se fait dans Transport → Conducteurs.'])
            ->add('licence', null, ['label' => 'N° de licence', 'required' => false])
            ->add('ancien', CheckboxType::class, ['label' => 'A quitté le club', 'required' => false])
            ->add('adresse', null, ['required' => false])
            ->add('codePostal', null, ['label' => 'Code postal', 'required' => false])
            ->add('ville', null, ['required' => false])
            ->add('telFixe', TelType::class, ['label' => 'Tél. fixe', 'required' => false])
            ->add('telPortable1', TelType::class, ['label' => 'Portable', 'required' => false])
            ->add('telPortable2', TelType::class, ['label' => 'Portable 2', 'required' => false])
            ->add('telProfessionnel', TelType::class, ['label' => 'Tél. professionnel', 'required' => false])
            ->add('email', EmailType::class, ['label' => 'E-mail', 'required' => false])
            ->add('memo', TextareaType::class, ['label' => 'Mémo', 'required' => false]);

        // Éducateur, dirigeant : équipes dont il s'occupe.
        if (is_a($options['data_class'], Educateur::class, true) || is_a($options['data_class'], Dirigeant::class, true)) {
            $builder->add('equipes', EntityType::class, [
                'class' => Categorie::class,
                'label' => 'Équipes',
                'multiple' => true,
                'expanded' => true,
                'required' => false,
                'by_reference' => false,
                'query_builder' => fn (EntityRepository $r) => $r->createQueryBuilder('c')->orderBy('c.ordre')->addOrderBy('c.nom'),
                'help' => 'Les équipes dont il s\'occupe cette saison.',
            ]);
        }

        // Arbitre : niveau et équipement fourni par le club.
        if (is_a($options['data_class'], Arbitre::class, true)) {
            $builder
                ->add('niveau', ChoiceType::class, ['label' => 'Niveau', 'required' => false, 'placeholder' => '—', 'choices' => array_combine(Arbitre::NIVEAUX, Arbitre::NIVEAUX)])
                ->add('equipement', TextareaType::class, ['label' => 'Équipement fourni', 'required' => false, 'attr' => ['rows' => 3, 'placeholder' => 'ex. : 2 tenues, sifflet, cartons, drapeaux, montre…']]);
        }
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => Intervenant::class]);
    }
}
