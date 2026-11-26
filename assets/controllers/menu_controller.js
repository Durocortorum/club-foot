import { Controller } from '@hotwired/stimulus';

/*
 * En-tête sur téléphone : ouvre et ferme le menu replié derrière « Plus » (barre du bas) ou « Menu ».
 * Turbo remplace l'en-tête à chaque page, le menu se referme donc tout seul après un clic.
 * Ouvert : un toucher à côté (sur le voile) ou la touche Échap le referme.
 */
export default class extends Controller {
    static targets = ['bouton'];

    connect() {
        this.fermerSiDehors = (event) => {
            if (this.element.classList.contains('ouvert') && event.target.classList?.contains('voile-menu')) this.basculer();
        };
        this.fermerSiEchap = (event) => { if (event.key === 'Escape' && this.element.classList.contains('ouvert')) this.basculer(); };
        document.addEventListener('click', this.fermerSiDehors);
        document.addEventListener('keydown', this.fermerSiEchap);
        this.indiquerRubrique();
    }

    /**
     * Page hors des quatre raccourcis (ex. Encadrement) : « Plus » prend le nom de la rubrique en cours (l'entrée
     * surlignée du menu) et se surligne, pour savoir où l'on est.
     */
    indiquerRubrique() {
        const plus = this.element.querySelector('.barre-plus');
        if (!plus || this.element.querySelector('.barre-app a.actif')) return;
        const actif = this.element.querySelector('.tiroir a.actif');
        if (!actif) return;
        const nom = [...actif.childNodes].filter((n) => !(n.classList?.contains('pastille') || n.classList?.contains('ico')))
            .map((n) => n.textContent).join(' ').replace(/\s+/g, ' ').trim();
        if (!nom) return;
        plus.classList.add('actif');
        plus.querySelector('span').textContent = nom;
        plus.title = `${nom} : toucher pour voir tout le menu`;
    }

    disconnect() {
        document.removeEventListener('click', this.fermerSiDehors);
        document.removeEventListener('keydown', this.fermerSiEchap);
    }

    basculer() {
        const ouvert = this.element.classList.toggle('ouvert');
        this.boutonTargets.forEach((b) => b.setAttribute('aria-expanded', ouvert));
    }
}
