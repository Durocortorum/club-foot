import { Controller } from '@hotwired/stimulus';

/*
 * Formulaire de tâche : la liste des joueurs ne propose que ceux de la catégorie choisie
 * (tous si « Tout le club »). Choisir un joueur alors qu'aucune catégorie n'est choisie
 * renseigne sa catégorie.
 * La liste est reconstruite plutôt que masquée : Safari ignore « hidden » sur les <option>.
 */
export default class extends Controller {
    static targets = ['categorie', 'joueur'];

    connect() {
        this.toutes = this.joueurTarget.innerHTML;
        this.filtrer();
    }

    filtrer() {
        const categorie = this.categorieTarget.value;
        const choisi = this.joueurTarget.value;
        this.joueurTarget.innerHTML = this.toutes;
        if (categorie) {
            this.joueurTarget.querySelectorAll('option[data-categorie]').forEach((o) => {
                if (o.dataset.categorie !== categorie) {
                    o.remove();
                }
            });
            this.joueurTarget.querySelectorAll('optgroup').forEach((g) => {
                if (!g.querySelector('option')) {
                    g.remove();
                }
            });
        }
        // Le joueur déjà choisi reste choisi s'il est dans la catégorie, sinon « Aucun ».
        this.joueurTarget.value = this.joueurTarget.querySelector(`option[value="${CSS.escape(choisi)}"]`) ? choisi : '';
    }

    joueurChoisi() {
        const option = this.joueurTarget.selectedOptions[0];
        if (!this.categorieTarget.value && option?.dataset.categorie) {
            this.categorieTarget.value = option.dataset.categorie;
            this.filtrer();
        }
    }
}
