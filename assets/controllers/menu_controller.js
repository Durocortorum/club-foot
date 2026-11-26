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
        this.ongletsDeRubrique();
        this.indiquerRubrique();
    }

    /**
     * Page d'une rubrique (Joueurs, Encadrement…) : ses onglets prennent la place des raccourcis dans la barre du bas,
     * « Plus » reste au bout (les raccourcis sont dans le menu qu'il ouvre). Une seule barre.
     */
    ongletsDeRubrique() {
        const barre = this.element.querySelector('.barre-app');
        const rubrique = document.querySelector('.barre-bas');
        const plus = barre?.querySelector('.barre-plus');
        if (!barre || !rubrique || !plus || barre.querySelector('.onglet-rubrique')) return;
        barre.querySelectorAll(':scope > a').forEach((a) => a.remove());
        const onglets = [...rubrique.querySelectorAll('a')];
        onglets.forEach((a) => {
            a.classList.add('onglet-rubrique');
            barre.insertBefore(a, plus);
        });
        // Plus de quatre onglets (ex. Entraînements) : la barre défile au doigt, l'onglet en cours est montré.
        if (onglets.length > 4) {
            barre.classList.add('defile');
            barre.querySelector('a.actif')?.scrollIntoView({ inline: 'center', block: 'nearest' });
        }
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
