import { Controller } from '@hotwired/stimulus';

/*
 * Page « Importer depuis la FFF » : reçoit dans l'ancre de l'adresse (#type=…&texte=…&src=…&titre=…)
 * ce que le bouton « ➜ Club Foot » a lu sur la page FFF, fait choisir l'équipe (retenue par page FFF
 * dans ce navigateur), puis envoie le texte à l'import des matchs ou du classement habituel.
 */
export default class extends Controller {
    static targets = ['recu', 'titre', 'resume', 'formulaire', 'jeton', 'texte', 'action', 'categorie'];
    static values = { calendrier: String, classement: String };

    connect() {
        const p = new URLSearchParams(window.location.hash.slice(1));
        const type = p.get('type');
        const texte = p.get('texte');
        if (!texte || !['calendrier', 'classement'].includes(type)) {
            return;
        }
        this.src = p.get('src') || '';
        const calendrier = type === 'calendrier';
        this.formulaireTarget.action = (calendrier ? this.calendrierValue : this.classementValue) + (calendrier ? '#apercu' : '#apercu-classement');
        this.jetonTarget.value = this.jetonTarget.dataset[type];
        this.actionTarget.value = calendrier ? 'analyser' : 'lire';
        this.texteTarget.value = texte;
        const nombre = calendrier ? texte.split(/\n\s*\n/).length : texte.split('\n').length;
        this.titreTarget.textContent = calendrier ? 'Calendrier reçu de la FFF' : 'Classement reçu de la FFF';
        this.resumeTarget.textContent = (p.get('titre') ? p.get('titre') + ' · ' : '')
            + nombre + (calendrier ? (nombre > 1 ? ' matchs' : ' match') : ' équipes') + ' lus sur la page.';
        try {
            const equipe = localStorage.getItem('fff-equipe:' + this.cle());
            if (equipe && this.categorieTarget.querySelector(`option[value="${CSS.escape(equipe)}"]`)) {
                this.categorieTarget.value = equipe;
            }
        } catch (e) { /* stockage indisponible : on choisit à chaque fois */ }
        this.recuTarget.hidden = false;
        this.recuTarget.scrollIntoView({ block: 'start' });
    }

    envoyer() {
        try {
            localStorage.setItem('fff-equipe:' + this.cle(), this.categorieTarget.value);
        } catch (e) { /* rien */ }
    }

    // Le bouton est fait pour la barre de favoris : cliqué ici, il expliquerait mal ce qu'il fait.
    pasIci(event) {
        event.preventDefault();
        alert('Faites glisser ce bouton dans la barre de favoris (sans relâcher le clic), puis utilisez-le sur une page du site de la FFF.');
    }

    // Une page FFF par équipe : on retient l'équipe sans le type de page (calendrier ou classement).
    cle() {
        return this.src.replace(/\/(resultat-calendrier|classement|calendrier|resultats?)\/?$/, '');
    }
}
