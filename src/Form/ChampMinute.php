<?php

namespace App\Form;

use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\Form\FormInterface;

/**
 * Minute d'un fait de match (but, carton) en liste déroulante selon la durée du match (« 23 », « 45+2 »),
 * plus la valeur déjà enregistrée ou envoyée si elle n'y figure pas : le champ est donc posé par
 * le formulaire parent, qui connaît la valeur. La liste est refaite dans la page si la catégorie
 * change (score_controller.js, remplirMinutes()).
 */
final class ChampMinute
{
    /**
     * @param callable(object): (string|null) $lire valeur enregistrée de l'objet du formulaire
     */
    public static function poser(FormBuilderInterface $builder, string $nom, string $label, callable $lire, int $duree, array $attr = []): void
    {
        // Posé d'emblée pour le modèle des nouvelles lignes d'une collection (prototype).
        $builder->add($nom, ChoiceType::class, self::options($label, null, $duree, $attr));
        $builder->addEventListener(FormEvents::PRE_SET_DATA, fn (FormEvent $e) => self::remplacer($e->getForm(), $nom, $label, null !== $e->getData() ? $lire($e->getData()) : null, $duree, $attr));
        $builder->addEventListener(FormEvents::PRE_SUBMIT, fn (FormEvent $e) => self::remplacer($e->getForm(), $nom, $label, isset($e->getData()[$nom]) ? (string) $e->getData()[$nom] : null, $duree, $attr));
    }

    /**
     * Minutes proposées pour un match de $duree minutes (90 : 1 à 45, 45+1 à 45+15, 46 à 90, 90+1 à 90+15),
     * puis les prolongations (un tiers de la durée) pour les matchs de coupe.
     * Même liste dans la page : score_controller.js, remplirMinutes().
     *
     * @return array<string, array<string, string>>
     */
    public static function minutes(int $duree = 90): array
    {
        $mi = intdiv($duree, 2);
        $prolongation = $duree + intdiv($duree, 3);
        $plage = fn (int $de, int $a) => array_combine(array_map(fn ($m) => "$m'", range($de, $a)), array_map('strval', range($de, $a)));
        $arrets = fn (int $fin) => array_combine(array_map(fn ($m) => "$fin+$m'", range(1, 15)), array_map(fn ($m) => "$fin+$m", range(1, 15)));

        return [
            '1re mi-temps' => $plage(1, $mi),
            'Arrêts de jeu 1re mi-temps' => $arrets($mi),
            '2e mi-temps' => $plage($mi + 1, $duree),
            'Arrêts de jeu 2e mi-temps' => $arrets($duree),
            'Prolongations' => $plage($duree + 1, $prolongation) + $arrets($prolongation),
        ];
    }

    private static function remplacer(FormInterface $form, string $nom, string $label, ?string $valeur, int $duree, array $attr): void
    {
        $form->add($nom, ChoiceType::class, self::options($label, $valeur, $duree, $attr));
    }

    private static function options(string $label, ?string $valeur, int $duree, array $attr): array
    {
        $choix = self::minutes($duree);
        if (null !== $valeur && preg_match('/^\d+(\+\d+)?$/', $valeur) && !\in_array($valeur, array_merge(...array_values($choix)), true)) {
            $choix['Autre'] = ["$valeur'" => $valeur];
        }

        return ['label' => $label, 'choices' => $choix, 'required' => false, 'placeholder' => '—', 'attr' => $attr];
    }
}
