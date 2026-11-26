import { Controller } from '@hotwired/stimulus';

/*
 * Onglets d'une page (analyse de l'adversaire…) : un bouton par onglet (data-onglet), un panneau par onglet
 * (data-onglet du même nom). L'onglet choisi est gardé dans l'adresse (#effectif) pour y revenir après un envoi.
 */
export default class extends Controller {
    static targets = ['bouton', 'panneau', 'formulaire'];

    connect() {
        const demande = window.location.hash.slice(1);
        const noms = this.boutonTargets.map((b) => b.dataset.onglet);
        this.montrer(noms.includes(demande) ? demande : noms[0]);
    }

    choisir(event) {
        event.preventDefault();
        // Changer d'onglet referme le formulaire « Modifier / compléter » : on voit le contenu de l'onglet.
        if (this.hasFormulaireTarget) this.formulaireTarget.open = false;
        this.montrer(event.currentTarget.dataset.onglet);
        history.replaceState(null, '', `#${event.currentTarget.dataset.onglet}`);
    }

    montrer(nom) {
        this.boutonTargets.forEach((b) => {
            const actif = b.dataset.onglet === nom;
            b.classList.toggle('actif', actif);
            b.setAttribute('aria-selected', String(actif));
        });
        this.panneauTargets.forEach((p) => { p.hidden = p.dataset.onglet !== nom; });
    }

    /** « Modifier / compléter » : formulaire ouvert et montré. */
    modifier(event) {
        if (!this.hasFormulaireTarget) return;
        event.preventDefault();
        this.formulaireTarget.open = true;
        this.formulaireTarget.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }
}
