<?php

namespace App\Form;

use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\Form\FormInterface;

/**
 * Note sur 10 en liste déroulante, par demi-point (« 6,5 »). Une note déjà enregistrée ou envoyée
 * hors de la liste (anciennes notes au dixième, ex. : 6,3) y est ajoutée pour ne rien perdre :
 * le champ est donc posé par le formulaire parent, qui connaît la valeur.
 */
final class ChampNote
{
    /**
     * Pose le champ $nom sur $builder et le reconstruit avec la valeur enregistrée, puis envoyée.
     *
     * @param callable(object): (float|null) $lire valeur enregistrée de l'objet du formulaire
     */
    public static function poser(FormBuilderInterface $builder, string $nom, string $label, callable $lire, array $attr = []): void
    {
        // Posé d'emblée pour le modèle des nouvelles lignes d'une collection (prototype).
        $builder->add($nom, ChoiceType::class, self::options($label, null, $attr));
        $builder->addEventListener(FormEvents::PRE_SET_DATA, fn (FormEvent $e) => self::remplacer($e->getForm(), $nom, $label, null !== $e->getData() ? $lire($e->getData()) : null, $attr));
        $builder->addEventListener(FormEvents::PRE_SUBMIT, fn (FormEvent $e) => self::remplacer($e->getForm(), $nom, $label, $e->getData()[$nom] ?? null, $attr));
    }

    private static function remplacer(FormInterface $form, string $nom, string $label, float|string|null $valeur, array $attr): void
    {
        $form->add($nom, ChoiceType::class, self::options($label, $valeur, $attr));
    }

    private static function options(string $label, float|string|null $valeur, array $attr): array
    {
        $choix = [];
        foreach (range(0, 20) as $demi) {
            $choix[self::libelle($demi / 2)] = $demi / 2;
        }
        if (is_numeric($valeur) && !\in_array((float) $valeur, $choix, false)) {
            $choix[self::libelle((float) $valeur)] = (float) $valeur;
            asort($choix);
        }

        return ['label' => $label, 'choices' => $choix, 'required' => false, 'placeholder' => '—', 'attr' => $attr];
    }

    private static function libelle(float $note): string
    {
        return str_replace('.', ',', (string) round($note, 1));
    }
}
