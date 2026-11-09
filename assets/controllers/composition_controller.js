import { Controller } from '@hotwired/stimulus';

/*
 * Schéma de composition : les titulaires (« pions ») se déplacent à la souris ou au doigt sur le terrain.
 * Les places (x, y en %, y de notre but vers le but adverse) sont rangées en JSON dans le champ caché du formulaire.
 */
export default class extends Controller {
    static targets = ['terrain', 'pion', 'champ'];

    connect() {
        this.pionTargets.forEach((pion) => {
            pion.addEventListener('pointerdown', (e) => this.saisir(e, pion));
        });
        this.terrainTarget.addEventListener('pointermove', (e) => this.glisser(e));
        this.terrainTarget.addEventListener('pointerup', () => this.lacher());
        this.terrainTarget.addEventListener('pointercancel', () => this.lacher());
        this.enregistrer();
    }

    saisir(event, pion) {
        event.preventDefault();
        this.pris = pion;
        pion.classList.add('deplace');
        this.terrainTarget.setPointerCapture(event.pointerId);
    }

    glisser(event) {
        if (!this.pris) return;
        const cadre = this.terrainTarget.getBoundingClientRect();
        const x = Math.min(100, Math.max(0, ((event.clientX - cadre.left) / cadre.width) * 100));
        const haut = Math.min(100, Math.max(0, ((event.clientY - cadre.top) / cadre.height) * 100));
        this.pris.style.left = `${x}%`;
        this.pris.style.top = `${haut}%`;
    }

    lacher() {
        if (!this.pris) return;
        this.pris.classList.remove('deplace');
        this.pris = null;
        this.enregistrer();
    }

    // Places de tous les pions, depuis leur position affichée.
    enregistrer() {
        const places = {};
        this.pionTargets.forEach((pion) => {
            const x = parseFloat(pion.style.left);
            const y = 100 - parseFloat(pion.style.top);
            places[pion.dataset.joueur] = [Math.round(x * 10) / 10, Math.round(y * 10) / 10];
        });
        this.champTarget.value = JSON.stringify(places);
    }
}
