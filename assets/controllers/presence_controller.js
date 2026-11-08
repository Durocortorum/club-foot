import { Controller } from '@hotwired/stimulus';

/*
 * Grille de présences : remplissage rapide d'un créneau et couleur des cases.
 * Le bouton ✓ d'un créneau se coche et se décoche :
 * - coché : tous les joueurs encore vides sont marqués présents (avec l'équipe de la semaine) ;
 * - décoché : la colonne est vidée des « présents » (les absences et les autres équipes restent).
 */
export default class extends Controller {
    static values = { equipe: String };

    connect() {
        this.element.addEventListener('change', (event) => {
            if (event.target.matches('select.creneau')) {
                this.colorer(event.target);
                this.etatBouton(event.target.dataset.creneau);
            }
        });
        this.element.querySelectorAll('[data-presence-creneau-param]').forEach((bouton) => {
            this.etatBouton(bouton.dataset.presenceCreneauParam);
        });
    }

    remplir({ params: { creneau } }) {
        const selects = [...this.element.querySelectorAll(`select[data-creneau="${creneau}"]`)];
        const vides = selects.filter((select) => select.value === '');
        if (vides.length) {
            vides.forEach((select) => { select.value = this.equipeValue; this.colorer(select); });
        } else {
            selects.filter((select) => select.value === this.equipeValue)
                .forEach((select) => { select.value = ''; this.colorer(select); });
        }
        this.etatBouton(creneau);
    }

    // Coché quand plus aucune case du créneau n'est vide (et qu'au moins un joueur est présent).
    etatBouton(creneau) {
        const bouton = this.element.querySelector(`[data-presence-creneau-param="${creneau}"]`);
        if (!bouton) return;
        const selects = [...this.element.querySelectorAll(`select[data-creneau="${creneau}"]`)];
        const coche = selects.length > 0
            && selects.every((select) => select.value !== '')
            && selects.some((select) => select.value === this.equipeValue);
        bouton.classList.toggle('coche', coche);
        bouton.setAttribute('aria-pressed', coche ? 'true' : 'false');
        bouton.title = coche
            ? `Décocher : vider les présents – ${bouton.dataset.libelle}`
            : `Cocher : tous présents – ${bouton.dataset.libelle}`;
    }

    colorer(select) {
        const absence = select.selectedOptions[0]?.parentElement?.label === 'Absence';
        select.classList.remove('vide', 'present', 'absence');
        select.classList.add(select.value === '' ? 'vide' : (absence ? 'absence' : 'present'));
    }
}
