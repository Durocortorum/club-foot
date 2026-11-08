import { Controller } from '@hotwired/stimulus';

/*
 * Statistiques de l'espace famille : n'affiche que la période choisie (toute la carrière ou une saison).
 */
export default class extends Controller {
    static targets = ['choix', 'periode'];

    connect() {
        this.afficher();
    }

    afficher() {
        const choisie = this.choixTarget.value;
        this.periodeTargets.forEach((bloc) => { bloc.hidden = bloc.dataset.periode !== choisie; });
    }
}
