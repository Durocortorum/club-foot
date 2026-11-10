<?php

namespace App\Form;

use App\Entity\Categorie;
use App\Entity\Joueur;
use App\Entity\Tache;
use App\Entity\Utilisateur;
use App\Service\Messagerie;
use Doctrine\ORM\EntityRepository;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class TacheType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('objet', null, ['attr' => ['placeholder' => 'Commander les maillots U13, préparer le tournoi…']])
            ->add('responsable', EntityType::class, [
                'label' => 'Personne de l\'encadrement',
                'class' => Utilisateur::class,
                'required' => false,
                'placeholder' => '— Aucune —',
                'query_builder' => fn (EntityRepository $r) => $r->createQueryBuilder('u')
                    ->where('u.famille = false')->orderBy('u.nom')->addOrderBy('u.prenom'),
                'choice_label' => fn (Utilisateur $u) => $u->getPrenom() . ' ' . $u->getNom(),
                'group_by' => fn (Utilisateur $u) => Messagerie::groupeEncadrement($u),
            ])
            ->add('joueur', EntityType::class, [
                'label' => 'ou un joueur',
                'class' => Joueur::class,
                'required' => false,
                'placeholder' => '— Aucun —',
                'query_builder' => fn (EntityRepository $r) => $r->createQueryBuilder('j')
                    ->leftJoin('j.categorie', 'c')->addSelect('c')
                    ->where('j.ancien = false')->orderBy('c.ordre')->addOrderBy('j.nom')->addOrderBy('j.prenom'),
                'choice_label' => fn (Joueur $j) => $j->getNom() . ' ' . $j->getPrenom(),
                'group_by' => fn (Joueur $j) => $j->getCategorie()?->getNom() ?? 'Sans catégorie',
                // Filtrage par catégorie dans la page (tache_controller.js).
                'choice_attr' => fn (Joueur $j) => $j->getCategorie() ? ['data-categorie' => $j->getCategorie()->getId()] : [],
                'attr' => ['data-tache-target' => 'joueur', 'data-action' => 'tache#joueurChoisi'],
                'help' => 'Joueurs de la catégorie choisie. Sa famille est prévenue par e-mail et voit la tâche dans son espace.',
            ])
            ->add('categorie', EntityType::class, [
                'label' => 'Catégorie',
                'class' => Categorie::class,
                'required' => false,
                'placeholder' => 'Tout le club',
                'query_builder' => fn (EntityRepository $r) => $r->createQueryBuilder('c')->orderBy('c.ordre')->addOrderBy('c.nom'),
                'choice_label' => 'nom',
                'attr' => ['data-tache-target' => 'categorie', 'data-action' => 'tache#filtrer'],
            ])
            ->add('echeance', DateType::class, ['label' => 'À faire pour le', 'widget' => 'single_text', 'input' => 'datetime_immutable', 'required' => false])
            ->add('urgente', CheckboxType::class, ['label' => 'Urgente', 'required' => false])
            ->add('description', TextareaType::class, ['label' => 'Détails', 'required' => false]);

        if (!$options['creation']) {
            $builder
                ->add('statut', ChoiceType::class, ['choices' => array_flip(Tache::STATUTS)])
                ->add('compteRendu', TextareaType::class, ['label' => 'Compte rendu, remarques', 'required' => false]);
        }
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => Tache::class, 'creation' => false]);
    }
}
