<?php

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Form\FormView;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Champ texte avec liste de suggestions. Contrairement à une liste déroulante,
 * il conserve les valeurs saisies librement dans Access qui ne figurent pas dans la liste.
 */
class ListeOuverteType extends AbstractType
{
    public function buildView(FormView $view, FormInterface $form, array $options): void
    {
        $view->vars['suggestions'] = $options['suggestions'];
        $view->vars['attr']['list'] = 'liste-' . md5(implode('|', $options['suggestions']));
        $view->vars['attr']['autocomplete'] = 'off';
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setRequired('suggestions');
        $resolver->setAllowedTypes('suggestions', 'array');
        $resolver->setDefault('required', false);
    }

    public function getParent(): string
    {
        return TextType::class;
    }
}
