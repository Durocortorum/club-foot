<?php

namespace App\Form;

use App\Entity\Detection;
use App\Entity\Joueur;
use Doctrine\ORM\EntityRepository;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\Extension\Core\Type\TelType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class DetectionType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $date = ['widget' => 'single_text', 'input' => 'datetime_immutable', 'required' => false];
        $postes = array_merge(Joueur::POSTES, ['Défenseur central', 'Milieu axe', 'Milieu offensif']);

        $builder
            // Détection
            ->add('dateDetection', DateType::class, ['label' => 'Date de la détection'] + $date)
            ->add('nombreSeances', IntegerType::class, ['label' => 'Séances suivies', 'required' => false, 'attr' => ['min' => 0]])
            ->add('numeroMaillot', null, ['label' => 'N° de maillot', 'required' => false])
            ->add('couleurMaillot', null, ['label' => 'Couleur du maillot', 'required' => false])
            ->add('retenu', CheckboxType::class, ['label' => 'Retenu (option)', 'required' => false])
            ->add('joueur', EntityType::class, [
                'class' => Joueur::class,
                'label' => 'Fiche joueur (s\'il a rejoint le club)',
                'required' => false,
                'placeholder' => '—',
                'choice_label' => fn (Joueur $j) => sprintf('%s %s (%s)', $j->getNom(), $j->getPrenom(), $j->getCategorie() ?? '?'),
                'query_builder' => fn (EntityRepository $r) => $r->createQueryBuilder('j')->orderBy('j.nom')->addOrderBy('j.prenom'),
            ])
            // Identité
            ->add('nom')
            ->add('prenom', null, ['label' => 'Prénom', 'required' => false])
            ->add('dateNaissance', DateType::class, ['label' => 'Date de naissance'] + $date)
            ->add('lieuNaissance', null, ['label' => 'Lieu de naissance', 'required' => false])
            ->add('photo', PhotoType::class)
            ->add('poids', NumberType::class, ['label' => 'Poids (kg)', 'required' => false, 'html5' => true, 'attr' => ['step' => 0.5]])
            ->add('taille', NumberType::class, ['label' => 'Taille (cm)', 'required' => false, 'html5' => true, 'attr' => ['step' => 1]])
            // Football
            ->add('poste', ListeOuverteType::class, ['suggestions' => $postes])
            ->add('posteSecondaire', ListeOuverteType::class, ['label' => 'Poste secondaire', 'suggestions' => $postes])
            ->add('pied', ChoiceType::class, ['label' => 'Pied fort', 'choices' => array_combine(Joueur::PIEDS, Joueur::PIEDS), 'required' => false, 'placeholder' => '—'])
            ->add('clubActuel', null, ['label' => 'Club actuel', 'required' => false])
            ->add('niveau', ListeOuverteType::class, ['label' => 'Niveau de l\'équipe', 'suggestions' => Detection::NIVEAUX])
            ->add('anneePremiereLicence', null, ['label' => '1re licence (année)', 'required' => false])
            ->add('educateurClub', null, ['label' => 'Éducateur du club', 'required' => false])
            ->add('telEducateur', TelType::class, ['label' => 'Tél. de l\'éducateur', 'required' => false])
            // Coordonnées
            ->add('adresse', null, ['required' => false])
            ->add('codePostal', null, ['label' => 'Code postal', 'required' => false])
            ->add('ville', null, ['required' => false])
            ->add('telFixe', TelType::class, ['label' => 'Tél. fixe', 'required' => false])
            ->add('telMobile', TelType::class, ['label' => 'Portable', 'required' => false])
            ->add('email', EmailType::class, ['label' => 'E-mail', 'required' => false])
            // Scolarité
            ->add('etablissementScolaire', null, ['label' => 'Établissement', 'required' => false])
            ->add('villeScolaire', null, ['label' => 'Ville de l\'établissement', 'required' => false])
            ->add('classe', null, ['required' => false])
            ->add('sectionSportive', CheckboxType::class, ['label' => 'Section sportive', 'required' => false])
            ->add('memo', TextareaType::class, ['label' => 'Observations', 'required' => false]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => Detection::class]);
    }
}
