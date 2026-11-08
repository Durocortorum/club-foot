import { Controller } from '@hotwired/stimulus';

/*
 * Formulaire « Nouvelle blessure » sans joueur fixé : le bouton « Localiser sur le corps » ouvre l'atlas
 * anatomique du joueur choisi dans la liste (inactif tant qu'aucun joueur n'est choisi).
 * Valeurs : select (id de la liste des joueurs), url (adresse de l'atlas, « 0 » à la place de l'id du joueur).
 */
export default class extends Controller {
    static targets = ['bouton', 'aide'];
    static values = { select: String, url: String };

    connect() {
        this.liste = document.getElementById(this.selectValue);
        this.majHandler = () => this.maj();
        this.liste?.addEventListener('change', this.majHandler);
        this.maj();
    }

    disconnect() {
        this.liste?.removeEventListener('change', this.majHandler);
    }

    maj() {
        const choisi = !!this.liste?.value;
        this.boutonTarget.disabled = !choisi;
        if (this.hasAideTarget) this.aideTarget.hidden = choisi;
    }

    ouvrir() {
        if (!this.liste?.value) return;
        window.location.href = this.urlValue.replace('/0/', `/${encodeURIComponent(this.liste.value)}/`);
    }
}
