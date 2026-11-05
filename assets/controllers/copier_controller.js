import { Controller } from '@hotwired/stimulus';

/*
 * Bouton « Copier » : met un texte (lien d'invitation, message) dans le presse-papiers.
 */
export default class extends Controller {
    static values = { texte: String };
    static targets = ['bouton'];

    async copier() {
        const libelle = this.boutonTarget.textContent;
        try {
            await navigator.clipboard.writeText(this.texteValue);
            this.boutonTarget.textContent = 'Copié ✓';
        } catch {
            // Presse-papiers refusé (page non sécurisée…) : on montre le texte à copier à la main.
            window.prompt('Copiez ce message :', this.texteValue);
        }
        setTimeout(() => { this.boutonTarget.textContent = libelle; }, 2000);
    }
}
