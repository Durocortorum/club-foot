import { Controller } from '@hotwired/stimulus';

/*
 * Présentation des tableaux tactiques pour la causerie : un tableau à la fois, boutons ou flèches du clavier
 * pour passer au suivant, plein écran (télévision, vidéoprojecteur, tablette).
 */
export default class extends Controller {
    static targets = ['diapo', 'compteur', 'precedent', 'suivant', 'boutonPleinEcran'];

    connect() {
        this.index = 0;
        this.surPleinEcran = () => this.afficher();
        document.addEventListener('fullscreenchange', this.surPleinEcran);
        if (!this.element.requestFullscreen) this.boutonPleinEcranTarget.hidden = true;
        this.afficher();
    }

    disconnect() {
        document.removeEventListener('fullscreenchange', this.surPleinEcran);
    }

    afficher() {
        this.diapoTargets.forEach((d, i) => { d.hidden = i !== this.index; });
        this.compteurTarget.textContent = `${this.index + 1} / ${this.diapoTargets.length}`;
        this.precedentTarget.disabled = this.index === 0;
        this.suivantTarget.disabled = this.index === this.diapoTargets.length - 1;
        this.boutonPleinEcranTarget.textContent = document.fullscreenElement ? '✕ Quitter le plein écran' : '⛶ Plein écran';
    }

    precedent() {
        if (this.index > 0) {
            this.index -= 1;
            this.afficher();
        }
    }

    suivant() {
        if (this.index < this.diapoTargets.length - 1) {
            this.index += 1;
            this.afficher();
        }
    }

    clavier(event) {
        if (['ArrowRight', 'PageDown', ' '].includes(event.key)) {
            event.preventDefault();
            this.suivant();
        } else if (['ArrowLeft', 'PageUp'].includes(event.key)) {
            event.preventDefault();
            this.precedent();
        }
    }

    async pleinEcran() {
        try {
            if (document.fullscreenElement) await document.exitFullscreen();
            else await this.element.requestFullscreen();
        } catch (e) { /* plein écran refusé par le navigateur */ }
    }
}
