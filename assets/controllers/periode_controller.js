import { Controller } from '@hotwired/stimulus';

/*
 * Choix d'une période (toute la carrière / toutes les saisons, ou une saison) :
 * - « periode » : blocs affichés seulement pour leur période (data-periode) ;
 * - « ligne » : lignes de matchs affichées pour leur saison (data-saison), ou toutes pour « carriere » / « toutes » ;
 * - « vide » : message affiché quand aucune ligne ne correspond.
 */
export default class extends Controller {
    static targets = ['choix', 'periode', 'ligne', 'vide'];

    connect() {
        this.afficher();
    }

    afficher() {
        const choisie = this.choixTarget.value;
        const tout = choisie === 'carriere' || choisie === 'toutes';
        this.periodeTargets.forEach((bloc) => { bloc.hidden = bloc.dataset.periode !== choisie; });
        let visibles = 0;
        this.ligneTargets.forEach((ligne) => {
            ligne.hidden = !tout && ligne.dataset.saison !== choisie;
            visibles += ligne.hidden ? 0 : 1;
        });
        this.videTargets.forEach((message) => { message.hidden = visibles > 0; });
    }
}
