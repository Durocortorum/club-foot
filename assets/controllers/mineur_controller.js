import { Controller } from '@hotwired/stimulus';

/*
 * Fiche joueur : n'affiche les champs des parents que si le joueur est mineur,
 * d'après la date de naissance saisie (sans date, il est considéré mineur).
 * Les champs masqués gardent leur valeur.
 */
export default class extends Controller {
    static targets = ['naissance', 'parents'];

    connect() {
        this.basculer();
    }

    basculer() {
        const mineur = this.age() === null || this.age() < 18;
        this.parentsTargets.forEach((bloc) => { bloc.hidden = !mineur; });
    }

    age() {
        const valeur = this.naissanceTarget.value;
        if (!valeur) return null;
        const naissance = new Date(valeur);
        const aujourdhui = new Date();
        let age = aujourdhui.getFullYear() - naissance.getFullYear();
        const mois = aujourdhui.getMonth() - naissance.getMonth();
        if (mois < 0 || (mois === 0 && aujourdhui.getDate() < naissance.getDate())) age--;
        return age;
    }
}
