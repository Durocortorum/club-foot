import { Controller } from '@hotwired/stimulus';

/*
 * Grille de présences : remplissage rapide d'un créneau et couleur des cases.
 */
export default class extends Controller {
    static values = { equipe: String };

    connect() {
        this.element.addEventListener('change', (event) => {
            if (event.target.matches('select.creneau')) {
                this.colorer(event.target);
            }
        });
    }

    // Marque présents, pour ce créneau, tous les joueurs dont la case est vide.
    remplir({ params: { creneau } }) {
        this.element.querySelectorAll(`select[data-creneau="${creneau}"]`).forEach((select) => {
            if (select.value === '') {
                select.value = this.equipeValue;
                this.colorer(select);
            }
        });
    }

    colorer(select) {
        const absence = select.selectedOptions[0]?.parentElement?.label === 'Absence';
        select.classList.remove('vide', 'present', 'absence');
        select.classList.add(select.value === '' ? 'vide' : (absence ? 'absence' : 'present'));
    }
}
