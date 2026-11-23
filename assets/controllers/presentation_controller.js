import { Controller } from '@hotwired/stimulus';

/*
 * Présentation des tableaux tactiques pour la causerie : un tableau à la fois, boutons ou flèches du clavier
 * pour passer au suivant, plein écran (télévision, vidéoprojecteur, tablette).
 *
 * Plein écran : celui du navigateur quand il existe (ordinateur, Android, iPad récent, préfixe « webkit »
 * pour Safari) ; sinon (iPhone), la présentation recouvre toute la page (classe « plein-ecran »).
 * Échap ou le bouton le quittent ; sur ordinateur, la touche F aussi.
 */
export default class extends Controller {
    static targets = ['diapo', 'compteur', 'precedent', 'suivant', 'boutonPleinEcran'];

    connect() {
        this.index = 0;
        this.surPleinEcran = () => this.afficher();
        document.addEventListener('fullscreenchange', this.surPleinEcran);
        document.addEventListener('webkitfullscreenchange', this.surPleinEcran);
        // Téléphone, tablette : glisser le doigt vers la gauche ou la droite change de tableau.
        this.element.addEventListener('touchstart', (e) => { this.depart = e.touches[0]; }, { passive: true });
        this.element.addEventListener('touchend', (e) => {
            const fin = e.changedTouches[0];
            if (!this.depart || !fin) return;
            const dx = fin.clientX - this.depart.clientX;
            const dy = fin.clientY - this.depart.clientY;
            if (Math.abs(dx) > 60 && Math.abs(dx) > 2 * Math.abs(dy)) dx < 0 ? this.suivant() : this.precedent();
            this.depart = null;
        }, { passive: true });
        this.afficher();
    }

    disconnect() {
        document.removeEventListener('fullscreenchange', this.surPleinEcran);
        document.removeEventListener('webkitfullscreenchange', this.surPleinEcran);
        this.quitterRecouvrement();
    }

    /** Élément en plein écran du navigateur (avec ou sans préfixe), sinon null. */
    elementPleinEcran() {
        return document.fullscreenElement || document.webkitFullscreenElement || null;
    }

    enPleinEcran() {
        return !!this.elementPleinEcran() || this.element.classList.contains('plein-ecran');
    }

    afficher() {
        this.diapoTargets.forEach((d, i) => { d.hidden = i !== this.index; });
        this.compteurTarget.textContent = `${this.index + 1} / ${this.diapoTargets.length}`;
        this.precedentTarget.disabled = this.index === 0;
        this.suivantTarget.disabled = this.index === this.diapoTargets.length - 1;
        this.boutonPleinEcranTarget.textContent = this.enPleinEcran() ? '✕ Quitter le plein écran' : '⛶ Plein écran';
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
        if (event.target.closest?.('input, textarea, select')) return;
        if (['ArrowRight', 'PageDown', ' '].includes(event.key)) {
            event.preventDefault();
            this.suivant();
        } else if (['ArrowLeft', 'PageUp'].includes(event.key)) {
            event.preventDefault();
            this.precedent();
        } else if (event.key === 'Escape' && this.element.classList.contains('plein-ecran')) {
            this.quitterRecouvrement();
            this.afficher();
        } else if (event.key === 'f' || event.key === 'F') {
            this.pleinEcran();
        }
    }

    async pleinEcran() {
        if (this.enPleinEcran()) {
            if (this.elementPleinEcran()) {
                try {
                    await (document.exitFullscreen?.() ?? document.webkitExitFullscreen?.());
                } catch (e) { /* déjà sorti */ }
            }
            this.quitterRecouvrement();
            this.afficher();
            return;
        }
        const demander = this.element.requestFullscreen ?? this.element.webkitRequestFullscreen;
        try {
            if (!demander) throw new Error('pas de plein écran');
            await demander.call(this.element);
        } catch (e) {
            // Navigateur sans plein écran (iPhone) ou refus : la présentation recouvre toute la page.
            this.element.classList.add('plein-ecran');
            document.documentElement.classList.add('sans-defilement');
        }
        this.afficher();
    }

    quitterRecouvrement() {
        this.element.classList.remove('plein-ecran');
        document.documentElement.classList.remove('sans-defilement');
    }
}
