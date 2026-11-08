import { Controller } from '@hotwired/stimulus';

/*
 * Nouveau message aux familles : n'affiche que le choix utile aux destinataires cochés
 * (catégorie, match, joueurs) ; recherche d'un joueur dans la liste.
 */
export default class extends Controller {
    static targets = ['bloc'];

    connect() {
        this.cible();
    }

    cible() {
        const choisie = this.element.querySelector('input[name$="[cible]"]:checked')?.value;
        this.blocTargets.forEach((b) => { b.hidden = b.dataset.cible !== choisie; });
    }

    chercher(event) {
        const texte = event.target.value.trim().toLowerCase();
        this.element.querySelectorAll('.case-joueur').forEach((l) => {
            l.hidden = texte !== '' && !l.dataset.nom.includes(texte);
        });
        this.element.querySelectorAll('.joueurs-choix fieldset').forEach((f) => {
            f.hidden = ![...f.querySelectorAll('.case-joueur')].some((l) => !l.hidden);
        });
    }
}
