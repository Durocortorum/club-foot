import { Controller } from '@hotwired/stimulus';

/*
 * Ajout et retrait de lignes dans une collection de formulaire Symfony
 * (feuille de match, buts, remplacements).
 */
export default class extends Controller {
    static targets = ['corps', 'ligne'];
    static values = { prototype: String, index: Number };

    ajouter() {
        const modele = document.createElement('template');
        modele.innerHTML = this.prototypeValue.replace(/__name__/g, this.indexValue++).trim();
        const ligne = modele.content.firstElementChild;
        this.corpsTarget.append(ligne);
        ligne.querySelector('input, select')?.focus();
    }

    retirer(event) {
        event.target.closest('tr').remove();
    }
}
