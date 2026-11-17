import { Controller } from '@hotwired/stimulus';
import { preparerBut, dessinerBut, ecouterBut, arrondir } from '../terrain_but.js';

/*
 * Feuille de match, bloc « Buts » : chaque but peut être placé sur le terrain (ballon au départ du tir, flèche
 * jusqu'au point d'entrée dans le but), comme en direct. Le tracé va dans un champ caché de la ligne du but.
 * Nos buts vont vers le but adverse (à droite), ceux de l'adversaire vers le nôtre (à gauche).
 */
export default class extends Controller {
    static targets = ['champ', 'dialogue', 'terrain', 'titre'];

    connect() {
        ecouterBut(this.terrainTarget, () => this.but);
    }

    /** Chaque ligne de but (y compris celles ajoutées) reçoit son bouton, à côté du champ caché. */
    champTargetConnected(champ) {
        if (champ.nextElementSibling?.matches('.bouton-trace')) return;
        const bouton = document.createElement('button');
        bouton.type = 'button';
        bouton.className = 'bouton petit bouton-trace';
        bouton.dataset.action = 'trace-but#ouvrir';
        champ.after(bouton);
        this.libeller(champ);
    }

    libeller(champ) {
        const bouton = champ.nextElementSibling;
        const place = champ.value !== '';
        bouton.textContent = place ? '✓ Placé' : '⚽ Placer';
        bouton.classList.toggle('place', place);
        bouton.title = place ? 'Modifier la place du but sur le terrain' : 'Placer le but sur le terrain (départ du ballon, entrée dans le but)';
    }

    ouvrir(event) {
        const ligne = event.currentTarget.closest('tr');
        this.champ = ligne.querySelector('[data-trace-but-target="champ"]');
        const camp = ligne.querySelector('[data-score-target="butCamp"]');
        const adverse = camp?.selectedOptions?.[0]?.dataset.adverse === '1';
        let trace = null;
        try {
            trace = this.champ.value ? JSON.parse(this.champ.value) : null;
        } catch (e) {
            trace = null;
        }
        // Tracé du mauvais côté (le camp du but a changé depuis) : on repart du point par défaut.
        if (trace && (trace.arrivee?.[0] > 52.5) === adverse) trace = null;
        this.but = preparerBut(!adverse, trace?.depart, trace?.arrivee);
        const minute = ligne.querySelector('.minute-but')?.selectedOptions?.[0]?.textContent?.trim();
        const buteur = adverse ? '' : ligne.querySelector('select[id$="_buteur"]')?.selectedOptions?.[0]?.textContent?.trim();
        this.titreTarget.textContent = `${adverse ? 'But adverse' : 'But'}${buteur && buteur !== '—' ? ` · ${buteur}` : ''}${minute && minute !== '—' ? ` · ${minute}` : ''}`;
        dessinerBut(this.terrainTarget, this.but);
        this.dialogueTarget.showModal();
    }

    enregistrer() {
        this.champ.value = JSON.stringify({ depart: arrondir(this.but.depart), arrivee: arrondir(this.but.arrivee) });
        this.fermer();
    }

    effacer() {
        this.champ.value = '';
        this.fermer();
    }

    fermer() {
        if (this.champ) this.libeller(this.champ);
        this.but = null;
        this.dialogueTarget.close();
    }
}
