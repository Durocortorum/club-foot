import { Controller } from '@hotwired/stimulus';

/*
 * Planches anatomiques du formulaire de blessure : navigation entre planches
 * et remplissage des champs zone, localisation et membre.
 */
export default class extends Controller {
    static targets = ['planche', 'titre', 'retour', 'choix'];
    static values = { zone: String, localisation: String, membre: String };

    connect() {
        this.resumer();
    }

    ouvrir({ params: { cible } }) {
        this.plancheTargets.forEach((planche) => {
            planche.hidden = planche.dataset.id !== cible;
            if (!planche.hidden) {
                this.titreTarget.textContent = planche.dataset.titre;
            }
        });
        this.retourTarget.hidden = cible === 'corps';
        this.marquer();
    }

    choisir({ params }) {
        if (params.endroit) {
            this.champ(this.zoneValue).value = params.endroit;
        } else {
            this.champ(this.localisationValue).value = params.localisation;
            this.champ(this.membreValue).value = params.membre;
            this.ouvrir({ params: { cible: params.cible } });
        }
        this.resumer();
        this.marquer();
    }

    // Met en évidence la zone déjà choisie sur la planche affichée.
    marquer() {
        const zone = this.champ(this.zoneValue).value;
        this.element.querySelectorAll('.point').forEach((point) => {
            point.classList.toggle('choisi', point.dataset.anatomieEndroitParam === zone);
        });
    }

    resumer() {
        const valeurs = [this.zoneValue, this.localisationValue].map((id) => this.champ(id).value).filter(Boolean);
        this.choixTarget.textContent = valeurs.length ? `Choix : ${valeurs.join(' – ')}` : '';
    }

    champ(id) {
        return document.getElementById(id);
    }
}
